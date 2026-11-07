<?php

namespace App\Fiscal\Documents;

use App\Models\Establishment;
use App\Models\LegalEntity;
use Illuminate\Support\Str;

/**
 * What the non-fiscal sheets (statement, quote) share: the company block as it
 * stands today, and the engine settings they were designed against.
 */
final class BusinessDocumentIssuer
{
    /** @var array{font: string, substitutions: bool, margin_top: int, margin_bottom: int, margin_header: int, margin_footer: int} */
    public const array ENGINE = [
        'font' => 'hankengrotesk',
        'substitutions' => true,
        'margin_top' => 14,
        'margin_bottom' => 20,
        'margin_header' => 8,
        'margin_footer' => 9,
    ];

    /**
     * @return array{legal_name: string, trade_name: string|null, tax_identification_number: string, address_line: string|null, municipality: string|null, province_code: string|null}
     */
    public static function of(LegalEntity $legalEntity, ?Establishment $establishment = null): array
    {
        $establishment ??= $legalEntity->establishments()->where('is_head_office', true)->first();

        return [
            'legal_name' => $legalEntity->legal_name,
            'trade_name' => $legalEntity->trade_name,
            'tax_identification_number' => (string) $legalEntity->tax_identification_number,
            'address_line' => $establishment?->address_line,
            'municipality' => $establishment?->municipality,
            'province_code' => $establishment?->province_code,
        ];
    }

    public static function slug(string $value): string
    {
        return Str::slug($value) ?: 'documento';
    }
}
