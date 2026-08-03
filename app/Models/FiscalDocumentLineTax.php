<?php

namespace App\Models;

use App\FiscalTaxType;
use Database\Factories\FiscalDocumentLineTaxFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property int $fiscal_document_line_id
 * @property FiscalTaxType $tax_type
 * @property string $tax_country_region
 * @property string|null $tax_code
 * @property int $tax_rate_basis_points
 * @property int $tax_contribution_minor
 * @property string|null $tax_exemption_code
 * @property-read FiscalDocumentLine $fiscalDocumentLine
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'fiscal_document_line_id',
    'tax_type',
    'tax_country_region',
    'tax_code',
    'tax_rate_basis_points',
    'tax_contribution_minor',
    'tax_exemption_code',
])]
class FiscalDocumentLineTax extends Model
{
    /** @use HasFactory<FiscalDocumentLineTaxFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function (self $tax): void {
                $tax->ensureDocumentIsMutable();
            });
        }
    }

    /** @return BelongsTo<FiscalDocumentLine, $this> */
    public function fiscalDocumentLine(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentLine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_type' => FiscalTaxType::class,
        ];
    }

    private function ensureDocumentIsMutable(): void
    {
        $line = $this->relationLoaded('fiscalDocumentLine')
            ? $this->fiscalDocumentLine
            : FiscalDocumentLine::query()
                ->with('fiscalDocument')
                ->find($this->fiscal_document_line_id);

        if (! $line instanceof FiscalDocumentLine || ! $line->fiscalDocument->isMutable()) {
            throw new DomainException('Taxes of an issued fiscal document are immutable.');
        }
    }
}
