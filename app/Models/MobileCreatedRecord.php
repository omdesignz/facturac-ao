<?php

namespace App\Models;

use Database\Factories\MobileCreatedRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a request from the phone to the customer or item it produced.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int|null $customer_id
 * @property int|null $catalogue_item_id
 * @property string $client_key
 * @property int $created_by_user_id
 * @property-read Customer|null $customer
 * @property-read CatalogueItem|null $catalogueItem
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'customer_id',
    'catalogue_item_id',
    'client_key',
    'created_by_user_id',
])]
class MobileCreatedRecord extends Model
{
    /** @use HasFactory<MobileCreatedRecordFactory> */
    use HasFactory;

    /** Not a column: whether this instance is a replay of an earlier request. */
    public bool $replayed = false;

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
}
