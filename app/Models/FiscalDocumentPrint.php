<?php

namespace App\Models;

use Database\Factories\FiscalDocumentPrintFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What it takes to print a fiscal document exactly as it was issued.
 *
 * PDFs are rendered on demand rather than stored, so this is the record that
 * keeps a re-render faithful: the frozen layout it is drawn with, the issuer
 * details as they stood on the day (a company that later moves or renames
 * must not rewrite its old invoices), the logo it carried, and a fingerprint
 * of every value it prints, checked before each render.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property string $layout_version
 * @property array{
 *     legal_name: string,
 *     trade_name: string|null,
 *     tax_identification_number: string,
 *     establishment: string,
 *     address_line: string|null,
 *     municipality: string|null,
 *     province_code: string|null,
 *     support_email: string|null
 * } $issuer
 * @property string|null $logo_path
 * @property string|null $logo_sha256
 * @property string $source_sha256
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'layout_version',
    'issuer',
    'logo_path',
    'logo_sha256',
    'source_sha256',
])]
class FiscalDocumentPrint extends Model
{
    /** @use HasFactory<FiscalDocumentPrintFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('How a document prints is fixed when it is issued.');
        });

        static::deleting(function (): never {
            throw new DomainException('The print record of an issued document cannot be deleted.');
        });
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issuer' => 'array',
        ];
    }
}
