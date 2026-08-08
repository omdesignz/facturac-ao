<?php

namespace App\Models;

use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one article costs on one price list.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $price_list_id
 * @property int $catalogue_item_id
 * @property int $unit_price_minor
 * @property string $currency_code
 * @property string|null $note
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'price_list_id',
    'catalogue_item_id',
    'unit_price_minor',
    'currency_code',
    'note',
])]
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer'];
    }
}
