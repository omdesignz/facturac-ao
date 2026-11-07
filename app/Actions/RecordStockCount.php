<?php

namespace App\Actions;

use App\Http\Controllers\Api\Mobile\MobileStockController;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A stocktake: what was counted on the shelves of one establishment, set as
 * the balance of each item, with every difference kept as an acerto.
 *
 * The count is blind: the phone never shows what the system expects while the
 * person counts, only the differences once they review it. Confirming sets
 * each balance to the count under a lock, so a sale in between is not counted
 * twice, and the same count sent again finds nothing more to correct.
 *
 * @phpstan-type CountLines list<array{catalogue_item_public_id: string, counted_quantity: string}>
 * @phpstan-type CountedLine array{catalogue_item_public_id: string, name: string, code: string, unit_of_measure: string, expected: string, counted: string, difference: string, difference_sign: int}
 */
final readonly class RecordStockCount
{
    public function __construct(private RecordStockMovement $stock) {}

    /**
     * Where a count may happen, the head office first.
     *
     * @return EloquentCollection<int, Establishment>
     */
    public function establishments(LegalEntity $legalEntity): EloquentCollection
    {
        return $legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->oldest('id')
            ->get();
    }

    /**
     * The differences the count would make, without writing anything.
     *
     * @param  CountLines  $lines
     * @return array{establishment: array{public_id: string, name: string}, lines: list<CountedLine>, changed: int, unchanged: int}
     */
    public function preview(LegalEntity $legalEntity, string $establishmentPublicId, array $lines): array
    {
        $establishment = $this->establishment($legalEntity, $establishmentPublicId);
        $items = $this->items($legalEntity, $lines);
        $levels = StockLevel::query()
            ->where('establishment_id', $establishment->id)
            ->whereIn('catalogue_item_id', $items->pluck('id'))
            ->pluck('quantity_units', 'catalogue_item_id');

        $counted = [];

        foreach ($lines as $index => $line) {
            $item = $items->get($line['catalogue_item_public_id']);
            $units = $this->units($item, $line['counted_quantity'], $index);
            $counted[] = $this->present($item, (int) ($levels[$item->id] ?? 0), $units);
        }

        return $this->summary($establishment, $counted);
    }

    /**
     * @param  array{client_key: string, establishment_public_id: string, lines: CountLines, note: string|null}  $count
     * @return array{establishment: array{public_id: string, name: string}, lines: list<CountedLine>, changed: int, unchanged: int, replayed: bool}
     */
    public function execute(LegalEntity $legalEntity, User $user, array $count): array
    {
        $establishment = $this->establishment($legalEntity, $count['establishment_public_id']);
        $reference = 'contagem:'.$count['client_key'];
        $items = $this->items($legalEntity, $count['lines']);

        // Units are checked before anything is written, so a typo on the last
        // line does not leave the first ones counted.
        $units = [];

        foreach ($count['lines'] as $index => $line) {
            $units[$index] = $this->units($items->get($line['catalogue_item_public_id']), $line['counted_quantity'], $index);
        }

        $replayed = StockMovement::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('reference', $reference)
            ->exists();

        $counted = DB::transaction(function () use ($count, $items, $units, $establishment, $user, $reference): array {
            $counted = [];

            foreach ($count['lines'] as $index => $line) {
                $item = $items->get($line['catalogue_item_public_id']);
                $movement = $this->stock->countTo($item, $establishment, $units[$index], $user, $reference, $count['note']);
                $before = $movement instanceof StockMovement
                    ? $movement->balance_after_units - $movement->quantity_units
                    : $units[$index];

                $counted[] = $this->present($item, $before, $units[$index]);
            }

            return $counted;
        });

        return [...$this->summary($establishment, $counted), 'replayed' => $replayed];
    }

    /**
     * @param  list<CountedLine>  $lines
     * @return array{establishment: array{public_id: string, name: string}, lines: list<CountedLine>, changed: int, unchanged: int}
     */
    private function summary(Establishment $establishment, array $lines): array
    {
        $changed = count(array_filter($lines, fn (array $line): bool => $line['difference_sign'] !== 0));

        return [
            'establishment' => ['public_id' => $establishment->public_id, 'name' => $establishment->name],
            'lines' => $lines,
            'changed' => $changed,
            'unchanged' => count($lines) - $changed,
        ];
    }

    /**
     * @return CountedLine
     */
    private function present(CatalogueItem $item, int $expectedUnits, int $countedUnits): array
    {
        $difference = $countedUnits - $expectedUnits;

        return [
            'catalogue_item_public_id' => $item->public_id,
            'name' => $item->name,
            'code' => $item->code,
            'unit_of_measure' => $item->unit_of_measure,
            'expected' => MobileStockController::quantity($expectedUnits, $item->stock_scale),
            'counted' => MobileStockController::quantity($countedUnits, $item->stock_scale),
            'difference' => ($difference > 0 ? '+' : ($difference < 0 ? '−' : '')).MobileStockController::quantity(abs($difference), $item->stock_scale),
            'difference_sign' => $difference <=> 0,
        ];
    }

    private function establishment(LegalEntity $legalEntity, string $publicId): Establishment
    {
        return $legalEntity->establishments()
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first()
            ?? throw ValidationException::withMessages([
                'establishment_public_id' => 'Este estabelecimento já não está disponível.',
            ]);
    }

    /**
     * @param  CountLines  $lines
     * @return Collection<string, CatalogueItem>
     */
    private function items(LegalEntity $legalEntity, array $lines): Collection
    {
        $items = CatalogueItem::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('tracks_stock', true)
            ->whereIn('public_id', array_column($lines, 'catalogue_item_public_id'))
            ->get()
            ->keyBy('public_id');

        foreach ($lines as $index => $line) {
            if (! $items->has($line['catalogue_item_public_id'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.catalogue_item_public_id" => 'Este artigo não controla inventário nesta empresa.',
                ]);
            }
        }

        return $items;
    }

    /** A typed count as the item's own scaled units, refusing more decimals than it keeps. */
    private function units(CatalogueItem $item, string $quantity, int $index): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($quantity), 2), 2, '');

        if (strlen(rtrim($fraction, '0')) > $item->stock_scale) {
            throw ValidationException::withMessages([
                "lines.{$index}.counted_quantity" => $item->stock_scale === 0
                    ? "{$item->name} conta-se em unidades inteiras."
                    : "{$item->name} conta-se com até {$item->stock_scale} casas decimais.",
            ]);
        }

        return (int) ($whole.substr(str_pad($fraction, $item->stock_scale, '0'), 0, $item->stock_scale));
    }
}
