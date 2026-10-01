<?php

namespace App\Fiscal;

use App\FiscalTaxType;

/**
 * Tax treatments that the fiscal-document editor can safely submit to AGT.
 *
 * Catalogue items, quotes, recurring profiles and fiscal drafts all pass
 * through this vocabulary. Keeping the mapping here prevents a visible 14%
 * rate from being stored without the matching NOR code.
 */
enum SupportedTaxTreatment: string
{
    case VatNormal14 = 'IVA_NOR_14';
    case VatExemptSimplified = 'IVA_ISE_M00';
    case NotSubject = 'NS_M02';
    case VatExemptExclusion = 'IVA_ISE_M04';

    public function label(): string
    {
        return match ($this) {
            self::VatNormal14 => 'IVA · taxa normal (14%)',
            self::VatExemptSimplified => 'Isento · regime simplificado (M00)',
            self::NotSubject => 'Não sujeito (M02)',
            self::VatExemptExclusion => 'Isento · exclusão (M04)',
        };
    }

    public function taxType(): FiscalTaxType
    {
        return match ($this) {
            self::VatNormal14,
            self::VatExemptSimplified,
            self::VatExemptExclusion => FiscalTaxType::Vat,
            self::NotSubject => FiscalTaxType::NotSubject,
        };
    }

    public function taxCode(): ?string
    {
        return match ($this) {
            self::VatNormal14 => 'NOR',
            self::VatExemptSimplified,
            self::VatExemptExclusion => 'ISE',
            self::NotSubject => null,
        };
    }

    public function percentage(): string
    {
        return $this === self::VatNormal14 ? '14' : '0';
    }

    public function exemptionCode(): ?string
    {
        return match ($this) {
            self::VatExemptSimplified => 'M00',
            self::NotSubject => 'M02',
            self::VatExemptExclusion => 'M04',
            self::VatNormal14 => null,
        };
    }

    /**
     * @return array{type: string, code: string|null, percentage: string, exemption_code: string|null}
     */
    public function profile(): array
    {
        return [
            'type' => $this->taxType()->value,
            'code' => $this->taxCode(),
            'percentage' => $this->percentage(),
            'exemption_code' => $this->exemptionCode(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, type: string, code: string|null, percentage: string, exemption_code: string|null}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $treatment): array => [
                'value' => $treatment->value,
                'label' => $treatment->label(),
                ...$treatment->profile(),
            ],
            self::cases(),
        );
    }

    public static function fromComponents(
        FiscalTaxType|string|null $type,
        ?string $code,
        mixed $percentage,
        ?string $exemptionCode,
    ): ?self {
        $type = $type instanceof FiscalTaxType ? $type->value : self::code($type);
        $code = self::code($code);
        $percentage = self::decimal($percentage);
        $exemptionCode = self::code($exemptionCode);

        foreach (self::cases() as $treatment) {
            if (
                $treatment->taxType()->value === $type
                && $treatment->taxCode() === $code
                && $treatment->percentage() === $percentage
                && $treatment->exemptionCode() === $exemptionCode
            ) {
                return $treatment;
            }
        }

        return null;
    }

    /**
     * Resolve older rows written before the UI always persisted the AGT code.
     *
     * Only an absent code is inferred. A contradictory explicit code remains
     * invalid instead of being silently rewritten.
     */
    public static function fromLegacyComponents(
        FiscalTaxType|string|null $type,
        ?string $code,
        mixed $percentage,
        ?string $exemptionCode,
    ): ?self {
        $strict = self::fromComponents($type, $code, $percentage, $exemptionCode);

        if ($strict instanceof self) {
            return $strict;
        }

        $type = $type instanceof FiscalTaxType ? $type->value : self::code($type);
        $code = self::code($code);
        $percentage = self::decimal($percentage);
        $exemptionCode = self::code($exemptionCode);

        if ($code !== null) {
            return null;
        }

        return match (true) {
            $type === 'IVA' && $percentage === '14' && $exemptionCode === null => self::VatNormal14,
            $type === 'IVA' && $percentage === '0' && $exemptionCode === 'M00' => self::VatExemptSimplified,
            $type === 'IVA' && $percentage === '0' && $exemptionCode === 'M04' => self::VatExemptExclusion,
            in_array($type, ['IVA', 'NS'], true)
                && $percentage === '0'
                && $exemptionCode === 'M02' => self::NotSubject,
            default => null,
        };
    }

    private static function code(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtoupper(trim($value));

        return $value === '' ? null : $value;
    }

    private static function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        if (preg_match('/\A(?:0|[1-9]\d*)(?:\.\d+)?\z/', $value) !== 1) {
            return null;
        }

        $normalised = str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;

        return $normalised === '' ? '0' : $normalised;
    }
}
