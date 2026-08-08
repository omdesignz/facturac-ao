<?php

namespace App\Models;

use Database\Factories\CustomerPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price agreed with one customer for one catalogue item.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $customer_id
 * @property int $catalogue_item_id
 * @property int $unit_price_minor
 * @property string $currency_code
 * @property string|null $note
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'customer_id',
    'catalogue_item_id',
    'unit_price_minor',
    'currency_code',
    'note',
])]
class CustomerPrice extends Model
{
    /** @use HasFactory<CustomerPriceFactory> */
    use HasFactory;

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer'];
    }
}
