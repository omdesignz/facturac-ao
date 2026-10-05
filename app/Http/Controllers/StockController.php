<?php

namespace App\Http\Controllers;

use App\Actions\RecordStockMovement;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StoreStockMovementRequest;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Workspace;
use App\StockMovementType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Inventário: what is on hand, what it is worth, and how it got that way.
 */
class StockController extends Controller
{
    public function __construct(private RecordStockMovement $recordStockMovement) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', CatalogueItem::class);

        $search = trim((string) $request->string('search'));
        $establishmentId = trim((string) $request->string('establishment'));
        $lowOnly = $request->boolean('low');

        $levels = StockLevel::query()
            ->with(['catalogueItem', 'establishment'])
            ->where('legal_entity_id', $legalEntity->id)
            ->whereHas('catalogueItem', fn ($query) => $query->where('tracks_stock', true))
            ->when($search !== '', fn ($query) => $query->whereHas(
                'catalogueItem',
                fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"),
            ))
            ->when($establishmentId !== '', fn ($query) => $query->whereHas(
                'establishment',
                fn ($nested) => $nested->where('public_id', $establishmentId),
            ))
            ->get()
            ->filter(fn (StockLevel $level): bool => ! $lowOnly || $level->isBelowReorderLevel())
            ->sortBy(fn (StockLevel $level): string => $level->catalogueItem->name)
            ->values();

        return Inertia::render('Stock/Index', [
            'levels' => array_values($levels
                ->map(fn (StockLevel $level): array => $this->presentLevel($level))
                ->all()),
            'summary' => [
                'tracked_items' => CatalogueItem::query()
                    ->where('legal_entity_id', $legalEntity->id)
                    ->where('tracks_stock', true)
                    ->count(),
                'low_count' => $levels
                    ->filter(fn (StockLevel $level): bool => $level->isBelowReorderLevel())
                    ->count(),
                'negative_count' => $levels
                    ->filter(fn (StockLevel $level): bool => $level->quantity_units < 0)
                    ->count(),
                'total_value_minor' => $levels->sum(
                    fn (StockLevel $level): int => $level->valueMinor(),
                ),
                'currency_code' => $legalEntity->establishments->first()?->getAttribute('currency_code') ?? 'AOA',
            ],
            'filters' => [
                'search' => $search,
                'establishment' => $establishmentId,
                'low' => $lowOnly,
            ],
            'establishments' => $this->establishments($legalEntity),
            'trackedItems' => $this->trackedItems($legalEntity),
            'movementTypes' => array_map(
                fn (StockMovementType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'carries_cost' => $type->carriesCost(),
                    'is_transfer' => $type === StockMovementType::TransferOut,
                ],
                StockMovementType::manual(),
            ),
            'movements' => $this->recentMovements($legalEntity),
            'canManage' => Gate::allows('create', CatalogueItem::class),
        ]);
    }

    public function store(
        StoreStockMovementRequest $request,
        RecordStockMovement $recordStockMovement,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return back()->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('create', CatalogueItem::class);

        $item = $this->findItem($legalEntity, (string) $request->validated('catalogue_item'));
        $origin = $this->findEstablishment($legalEntity, (string) $request->validated('establishment'));
        $type = StockMovementType::from((string) $request->validated('type'));

        $quantity = $this->toScaledUnits((string) $request->validated('quantity'), $item->stock_scale);
        $unitCost = $type->carriesCost()
            ? $this->toMicros((string) ($request->validated('unit_cost') ?? '0'))
            : null;

        try {
            if ($type === StockMovementType::TransferOut) {
                $this->transfer($request, $legalEntity, $item, $origin, $quantity);
            } else {
                $recordStockMovement->execute(
                    item: $item,
                    establishment: $origin,
                    type: $type,
                    quantityUnits: $quantity,
                    actor: $request->user(),
                    unitCostMicros: $unitCost,
                    note: $request->validated('note'),
                );
            }
        } catch (InvalidArgumentException|BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Movimento registado.');
    }

    /**
     * A transfer is two movements, not one, so both establishments keep a
     * complete history of what passed through them. Wrapped together so stock
     * can never leave one without arriving at the other.
     */
    private function transfer(
        StoreStockMovementRequest $request,
        LegalEntity $legalEntity,
        CatalogueItem $item,
        Establishment $origin,
        int $quantity,
    ): void {
        $destination = $this->findEstablishment(
            $legalEntity,
            (string) $request->validated('destination_establishment'),
        );

        DB::transaction(function () use ($request, $item, $origin, $destination, $quantity): void {
            $out = $this->recordStockMovement->execute(
                item: $item,
                establishment: $origin,
                type: StockMovementType::TransferOut,
                quantityUnits: $quantity,
                actor: $request->user(),
                reference: $destination->code,
                note: $request->validated('note'),
            );

            $this->recordStockMovement->execute(
                item: $item,
                establishment: $destination,
                type: StockMovementType::TransferIn,
                quantityUnits: $quantity,
                actor: $request->user(),
                // Carried at what it was worth when it left, so moving stock
                // between warehouses does not change its valuation.
                unitCostMicros: $out->unit_cost_micros,
                reference: $origin->code,
                note: $request->validated('note'),
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLevel(StockLevel $level): array
    {
        $item = $level->catalogueItem;

        return [
            'id' => $level->id,
            'item_public_id' => $item->public_id,
            'code' => $item->code,
            'name' => $item->name,
            'unit_of_measure' => $item->unit_of_measure,
            'establishment' => $level->establishment->name,
            'establishment_public_id' => $level->establishment->public_id,
            'quantity' => $this->fromScaledUnits($level->quantity_units, $level->quantity_scale),
            'reorder_level' => $item->reorder_level_units === null
                ? null
                : $this->fromScaledUnits($item->reorder_level_units, $item->stock_scale),
            'average_cost_minor' => intdiv($level->average_cost_micros, 1_000_000),
            'value_minor' => $level->valueMinor(),
            'below_reorder' => $level->isBelowReorderLevel(),
            'negative' => $level->quantity_units < 0,
            'last_movement_at' => $level->last_movement_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMovements(LegalEntity $legalEntity): array
    {
        return array_values(StockMovement::query()
            ->with(['catalogueItem', 'establishment', 'createdBy'])
            ->where('legal_entity_id', $legalEntity->id)
            ->latest('moved_at')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (StockMovement $movement): array => [
                'public_id' => $movement->public_id,
                'item' => $movement->catalogueItem->name,
                'code' => $movement->catalogueItem->code,
                'establishment' => $movement->establishment->name,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'quantity' => $this->fromScaledUnits(
                    $movement->quantity_units,
                    $movement->quantity_scale,
                ),
                'balance_after' => $this->fromScaledUnits(
                    $movement->balance_after_units,
                    $movement->quantity_scale,
                ),
                'reference' => $movement->reference,
                'note' => $movement->note,
                'actor' => $movement->createdBy?->name,
                'automatic' => $movement->type->isAutomatic(),
                'moved_at' => $movement->moved_at->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function establishments(LegalEntity $legalEntity): array
    {
        return array_values($legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->oldest('id')
            ->get()
            ->map(fn (Establishment $establishment): array => [
                'value' => $establishment->public_id,
                'label' => $establishment->name,
            ])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function trackedItems(LegalEntity $legalEntity): array
    {
        return array_values(CatalogueItem::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('tracks_stock', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogueItem $item): array => [
                'value' => $item->public_id,
                'label' => "{$item->code} — {$item->name}",
            ])
            ->all());
    }

    private function findItem(LegalEntity $legalEntity, string $publicId): CatalogueItem
    {
        return CatalogueItem::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function findEstablishment(LegalEntity $legalEntity, string $publicId): Establishment
    {
        return Establishment::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * Parses a typed decimal into scaled integer units without ever touching a
     * float, so "1.005" cannot arrive as 1.0049999.
     */
    private function toScaledUnits(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return (int) ($whole.$fraction);
    }

    /**
     * A cost typed in kwanzas, as micros of a minor unit.
     *
     * One kwanza is 100 minor units and one minor unit is 1e6 micros, so the
     * form's "6500" becomes 6.5e11 — parsing it at any other scale values the
     * whole warehouse wrong by a factor of a hundred.
     */
    private function toMicros(string $value): int
    {
        return $this->toScaledUnits($value, 8);
    }

    /** Formats scaled units back into a plain decimal string. */
    private function fromScaledUnits(int $units, int $scale): string
    {
        if ($scale === 0) {
            return (string) $units;
        }

        $negative = $units < 0;
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$scale);
        $fraction = rtrim(substr($digits, -$scale), '0');

        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : ".{$fraction}");
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->with('establishments')->oldest('id')->first();
    }
}
