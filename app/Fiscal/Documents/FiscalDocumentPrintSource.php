<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use Illuminate\Support\Facades\DB;

/**
 * The fingerprint of everything a fiscal document prints.
 *
 * Read straight from the stored rows, not from models or the presenter: casts
 * and formatting are code, and code may change between the day a document is
 * issued and the day it is printed again. The raw values may not. The issuer
 * snapshot, logo and layout are folded in, so the fingerprint names the
 * whole sheet, not just its fiscal figures.
 */
class FiscalDocumentPrintSource
{
    /**
     * The document's own columns that print. Not the ones that move after
     * issue by design: AGT status, delivery tracking, timestamps.
     */
    private const array DOCUMENT_COLUMNS = [
        'public_id',
        'document_type',
        'document_no',
        'document_date',
        'due_date',
        'issued_at',
        'currency_code',
        'exchange_rate_micro',
        'customer_name',
        'customer_tax_identification_number',
        'customer_country_code',
        'customer_address',
        'notes',
        'payment_method',
        'payment_amount_minor',
        'payment_date',
        'references_document_no',
        'adjustment_reason',
        'settlement_total_minor',
        'net_total_minor',
        'tax_payable_minor',
        'gross_total_minor',
        'software_product_id',
        'software_validation_number',
        'document_payload_sha256',
    ];

    /** Child tables whose every row prints, in full. */
    private const array CHILD_TABLES = [
        'fiscal_document_lines',
        'fiscal_document_line_taxes',
        'fiscal_document_withholdings',
        'fiscal_document_settlements',
    ];

    /** @param  array<string, string|null>  $issuer */
    public function fingerprint(
        FiscalDocument $document,
        string $layoutVersion,
        array $issuer,
        ?string $logoSha256,
    ): string {
        $row = (array) DB::table('fiscal_documents')->where('id', $document->id)->first();

        $source = [
            'layout' => $layoutVersion,
            'issuer' => $this->normalise($issuer),
            'logo' => $logoSha256,
            'document' => $this->normalise(array_intersect_key($row, array_flip(self::DOCUMENT_COLUMNS))),
        ];

        foreach (self::CHILD_TABLES as $table) {
            $source[$table] = DB::table($table)
                ->where('fiscal_document_id', $document->id)
                ->orderBy('id')
                ->get()
                ->map(fn (object $child): array => $this->normalise(
                    array_diff_key((array) $child, ['created_at' => true, 'updated_at' => true]),
                ))
                ->all();
        }

        return hash('sha256', (string) json_encode(
            $source,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Keys sorted and every scalar as a string, so the same stored values
     * hash the same whichever driver returned them as int or string.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function normalise(array $values): array
    {
        ksort($values);

        return array_map(
            fn (mixed $value): ?string => $value === null ? null : (string) $value,
            $values,
        );
    }
}
