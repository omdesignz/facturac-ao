<?php

namespace App\Models;

use Database\Factories\TransportDocumentLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $transport_document_id
 * @property int|null $catalogue_item_id
 * @property int $line_number
 * @property string $product_code
 * @property string $product_description
 * @property int $quantity_units
 * @property int $quantity_scale
 * @property string $unit_of_measure
 * @property int $unit_price_minor
 * @property int $net_amount_minor
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'transport_document_id',
    'catalogue_item_id',
    'line_number',
    'product_code',
    'product_description',
    'quantity_units',
    'quantity_scale',
    'unit_of_measure',
    'unit_price_minor',
    'net_amount_minor',
])]
class TransportDocumentLine extends Model
{
    /** @use HasFactory<TransportDocumentLineFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function (self $line): void {
                $line->ensureDocumentIsMutable();
            });
        }
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<TransportDocument, $this> */
    public function transportDocument(): BelongsTo
    {
        return $this->belongsTo(TransportDocument::class);
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'quantity_scale' => 'integer',
            'unit_price_minor' => 'integer',
            'net_amount_minor' => 'integer',
        ];
    }

    private function ensureDocumentIsMutable(): void
    {
        $document = $this->relationLoaded('transportDocument')
            ? $this->transportDocument
            : TransportDocument::query()->find($this->transport_document_id);

        if (! $document instanceof TransportDocument || ! $document->isMutable()) {
            throw new DomainException('Lines of an issued transport document are immutable.');
        }
    }
}
