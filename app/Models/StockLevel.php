<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The running balance of one item in one establishment.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $catalogue_item_id
 * @property int $establishment_id
 * @property int $quantity_units
 * @property int $quantity_scale
 * @property int $average_cost_micros
 * @property CarbonImmutable|null $last_movement_at
 * @property-read CatalogueItem $catalogueItem
 * @property-read Establishment $establishment
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'catalogue_item_id',
    'establishment_id',
    'quantity_units',
    'quantity_scale',
    'average_cost_micros',
])]
class StockLevel extends Model
{
    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** What the balance on hand is worth, in minor currency units. */
    public function valueMinor(): int
    {
        if ($this->quantity_units <= 0) {
            return 0;
        }

        return intdiv(
            $this->quantity_units * $this->average_cost_micros,
            10 ** $this->quantity_scale * 1_000_000,
        );
    }

    public function isBelowReorderLevel(): bool
    {
        $reorder = $this->catalogueItem->reorder_level_units;

        return $reorder !== null && $this->quantity_units <= $reorder;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'quantity_scale' => 'integer',
            'average_cost_micros' => 'integer',
            'last_movement_at' => 'immutable_datetime',
        ];
    }
}
