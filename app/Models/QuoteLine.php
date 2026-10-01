<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offered line on a quote.
 *
 * @property int $id
 * @property int $quote_id
 * @property int $line_number
 * @property string|null $product_code
 * @property string $operation_type
 * @property string $product_description
 * @property string $unit_of_measure
 * @property int $quantity_units
 * @property int $quantity_scale
 * @property int $unit_price_minor
 * @property int $discount_rate_basis_points
 * @property string $tax_type
 * @property string|null $tax_code
 * @property string $tax_percentage
 * @property string|null $tax_exemption_code
 * @property int $net_amount_minor
 * @property int $tax_amount_minor
 * @property int $gross_amount_minor
 */
#[Fillable([
    'line_number',
    'product_code',
    'operation_type',
    'product_description',
    'unit_of_measure',
    'quantity_units',
    'quantity_scale',
    'unit_price_minor',
    'discount_rate_basis_points',
    'tax_type',
    'tax_code',
    'tax_percentage',
    'tax_exemption_code',
    'net_amount_minor',
    'tax_amount_minor',
    'gross_amount_minor',
])]
class QuoteLine extends Model
{
    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function (self $line): void {
                $quote = $line->relationLoaded('quote')
                    ? $line->quote
                    : Quote::query()->find($line->quote_id);

                if (! $quote instanceof Quote || ! $quote->status->isEditable()) {
                    throw new DomainException('Lines of a sent quote are immutable.');
                }
            });
        }
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'quantity_scale' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_rate_basis_points' => 'integer',
            'net_amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'gross_amount_minor' => 'integer',
        ];
    }
}
