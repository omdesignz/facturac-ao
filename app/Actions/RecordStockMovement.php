<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\StockRanLow;
use App\StockMovementType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place a stock balance changes.
 *
 * Everything — issuing an invoice, a manual acerto, a transfer — comes through
 * here, so the ledger and the balance can never disagree: they are written
 * inside one transaction, against a locked row.
 */
class RecordStockMovement
{
    /**
     * @param  int  $quantityUnits  Always positive; direction comes from the type.
     * @param  int|null  $unitCostMicros  Required for inbound types that carry cost.
     */
    public function execute(
        CatalogueItem $item,
        Establishment $establishment,
        StockMovementType $type,
        int $quantityUnits,
        ?User $actor = null,
        ?int $unitCostMicros = null,
        ?int $fiscalDocumentId = null,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        if ($quantityUnits <= 0) {
            throw new InvalidArgumentException('A quantidade tem de ser maior que zero.');
        }

        if (! $item->tracks_stock) {
            throw BillingActionRefused::because("O artigo {$item->code} não controla inventário.");
        }

        if ($item->legal_entity_id !== $establishment->legal_entity_id) {
            throw BillingActionRefused::because('O artigo e o estabelecimento pertencem a empresas diferentes.');
        }

        return DB::transaction(function () use (
            $item,
            $establishment,
            $type,
            $quantityUnits,
            $actor,
            $unitCostMicros,
            $fiscalDocumentId,
            $reference,
            $note,
        ): StockMovement {
            $level = $this->lockedLevel($item, $establishment);

            $signed = $type->isOutbound() ? -$quantityUnits : $quantityUnits;
            $balanceBefore = $level->quantity_units;
            $balanceAfter = $balanceBefore + $signed;

            $costMicros = $this->resolveCost($level, $type, $unitCostMicros);

            // The average only moves on the way in. Selling at a loss does not
            // change what the remaining units cost to acquire.
            if ($signed > 0 && $type->carriesCost()) {
                $level->average_cost_micros = $this->blendedAverage(
                    $balanceBefore,
                    $level->average_cost_micros,
                    $signed,
                    $costMicros,
                );
            }

            $level->quantity_units = $balanceAfter;
            $level->last_movement_at = now();
            $level->save();

            $this->warnIfLow($item, $establishment, $balanceBefore, $balanceAfter);

            return StockMovement::query()->create([
                'workspace_id' => $item->workspace_id,
                'legal_entity_id' => $item->legal_entity_id,
                'catalogue_item_id' => $item->id,
                'establishment_id' => $establishment->id,
                'type' => $type,
                'quantity_units' => $signed,
                'quantity_scale' => $level->quantity_scale,
                'balance_after_units' => $balanceAfter,
                'unit_cost_micros' => $costMicros,
                'fiscal_document_id' => $fiscalDocumentId,
                'created_by_user_id' => $actor?->id,
                'reference' => $reference,
                'note' => $note,
                'moved_at' => now(),
            ]);
        });
    }

    /**
     * Says something the first time a movement takes an article below its
     * reorder level.
     *
     * Only on the crossing, not on every sale after it: an article that is
     * already short is not fresh news each time one more leaves the shelf.
     */
    private function warnIfLow(
        CatalogueItem $item,
        Establishment $establishment,
        int $balanceBefore,
        int $balanceAfter,
    ): void {
        $reorder = $item->reorder_level_units;

        if ($reorder === null || $balanceAfter > $reorder || $balanceBefore <= $reorder) {
            return;
        }

        $workspace = $establishment->workspace;

        if ($workspace instanceof Workspace) {
            StockRanLow::fromLevel($item, $establishment, $balanceAfter, $reorder)
                ->sendToWorkspace($workspace);
        }
    }

    /**
     * The balance row for this item and establishment, locked for update.
     *
     * Created on first use rather than up front, so adding an establishment
     * does not have to backfill a row for every article in the catalogue.
     */
    private function lockedLevel(CatalogueItem $item, Establishment $establishment): StockLevel
    {
        $level = StockLevel::query()
            ->where('catalogue_item_id', $item->id)
            ->where('establishment_id', $establishment->id)
            ->lockForUpdate()
            ->first();

        if ($level instanceof StockLevel) {
            return $level;
        }

        return StockLevel::query()->create([
            'workspace_id' => $item->workspace_id,
            'legal_entity_id' => $item->legal_entity_id,
            'catalogue_item_id' => $item->id,
            'establishment_id' => $establishment->id,
            'quantity_units' => 0,
            'quantity_scale' => $item->stock_scale,
            'average_cost_micros' => 0,
        ]);
    }

    /**
     * Outbound movements are valued at the average the stock was carried at, so
     * the ledger records what leaving actually cost rather than what it sold for.
     */
    private function resolveCost(
        StockLevel $level,
        StockMovementType $type,
        ?int $unitCostMicros,
    ): int {
        if (! $type->carriesCost()) {
            return $level->average_cost_micros;
        }

        if ($unitCostMicros === null || $unitCostMicros < 0) {
            throw new InvalidArgumentException(
                'Indique o custo unitário para uma entrada em inventário.',
            );
        }

        return $unitCostMicros;
    }

    /**
     * Weighted average of what is already on hand and what is arriving.
     *
     * Stock that went negative is treated as starting from zero: averaging
     * against a balance that does not exist would produce a cost per unit with
     * no meaning.
     */
    private function blendedAverage(
        int $balanceBefore,
        int $currentAverage,
        int $incomingUnits,
        int $incomingCost,
    ): int {
        $existing = max(0, $balanceBefore);
        $totalUnits = $existing + $incomingUnits;

        if ($totalUnits <= 0) {
            return $incomingCost;
        }

        return intdiv(
            ($existing * $currentAverage) + ($incomingUnits * $incomingCost),
            $totalUnits,
        );
    }
}
