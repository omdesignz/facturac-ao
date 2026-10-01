<?php

namespace App\Models;

use App\FiscalOperationType;
use Carbon\CarbonImmutable;
use Database\Factories\FiscalDocumentLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property int $line_number
 * @property FiscalOperationType $operation_type
 * @property CarbonImmutable|null $operation_date
 * @property string $product_code
 * @property string $product_description
 * @property int $quantity_units
 * @property int $quantity_scale
 * @property string $unit_of_measure
 * @property int $unit_price_base_minor
 * @property int $unit_price_micros
 * @property int $discount_rate_basis_points
 * @property int $base_amount_minor
 * @property int $settlement_amount_minor
 * @property int $net_amount_minor
 * @property int $tax_amount_minor
 * @property int $gross_amount_minor
 * @property-read FiscalDocument $fiscalDocument
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'line_number',
    'operation_type',
    'operation_date',
    'product_code',
    'product_description',
    'quantity_units',
    'quantity_scale',
    'unit_of_measure',
    'unit_price_base_minor',
    'unit_price_micros',
    'discount_rate_basis_points',
    'base_amount_minor',
    'settlement_amount_minor',
    'net_amount_minor',
    'tax_amount_minor',
    'gross_amount_minor',
])]
class FiscalDocumentLine extends Model
{
    /** @use HasFactory<FiscalDocumentLineFactory> */
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

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return HasMany<FiscalDocumentLineTax, $this> */
    public function taxes(): HasMany
    {
        return $this->hasMany(FiscalDocumentLineTax::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_type' => FiscalOperationType::class,
            'operation_date' => 'immutable_date',
        ];
    }

    private function ensureDocumentIsMutable(): void
    {
        $document = $this->relationLoaded('fiscalDocument')
            ? $this->fiscalDocument
            : FiscalDocument::query()->find($this->fiscal_document_id);

        if (! $document instanceof FiscalDocument || ! $document->isMutable()) {
            throw new DomainException('Lines of an issued fiscal document are immutable.');
        }
    }
}
