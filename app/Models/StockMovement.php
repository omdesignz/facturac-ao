<?php

namespace App\Models;

use App\StockMovementType;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the stock ledger.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $catalogue_item_id
 * @property int $establishment_id
 * @property StockMovementType $type
 * @property int $quantity_units
 * @property int $quantity_scale
 * @property int $balance_after_units
 * @property int $unit_cost_micros
 * @property int|null $fiscal_document_id
 * @property int|null $created_by_user_id
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon $moved_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'catalogue_item_id',
    'establishment_id',
    'type',
    'quantity_units',
    'quantity_scale',
    'balance_after_units',
    'unit_cost_micros',
    'fiscal_document_id',
    'created_by_user_id',
    'reference',
    'note',
    'moved_at',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

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

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity_units' => 'integer',
            'quantity_scale' => 'integer',
            'balance_after_units' => 'integer',
            'unit_cost_micros' => 'integer',
            'moved_at' => 'immutable_datetime',
        ];
    }
}
