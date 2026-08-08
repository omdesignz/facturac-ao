<?php

namespace App;

/**
 * Tax the buyer keeps back and pays to the AGT itself, rather than paying it
 * to the seller and trusting them to pass it on.
 *
 * Angola uses this in two quite different ways, and the difference matters for
 * the arithmetic: the income taxes are charged on the value of the supply, while
 * captive VAT is a slice of the VAT already on the document.
 */
enum WithholdingType: string
{
    case IncomeTax = 'IRT';
    case IndustrialTax = 'II';
    case CaptiveVat = 'IVA-CATIVO';

    public function label(): string
    {
        return match ($this) {
            self::IncomeTax => 'Retenção na fonte',
            self::IndustrialTax => 'Retenção na fonte',
            self::CaptiveVat => 'Cativação',
        };
    }

    /** The tax being withheld, as it is named in the "Imposto" column. */
    public function taxLabel(): string
    {
        return match ($this) {
            self::IncomeTax => 'IRT',
            self::IndustrialTax => 'Imposto Industrial',
            self::CaptiveVat => 'IVA',
        };
    }

    /**
     * Whether the rate is charged on the VAT rather than on the supply.
     *
     * Captive VAT is the buyer paying part of the tax straight to the AGT, so
     * its base is the VAT figure. The income taxes are charged on what the
     * supply was worth, before VAT was added.
     */
    public function isLeviedOnVat(): bool
    {
        return $this === self::CaptiveVat;
    }

    /**
     * The rate normally applied, in basis points.
     *
     * A starting point for the form, not a rule: the captive share depends on
     * who the buyer is, and the income-tax rate on what was supplied. Both are
     * left editable because only the person issuing the document knows which
     * case they are in.
     */
    public function suggestedRateBasisPoints(): int
    {
        return match ($this) {
            self::IncomeTax => 650,
            self::IndustrialTax => 650,
            self::CaptiveVat => 5_000,
        };
    }

    /**
     * @return list<array{value: string, label: string, tax: string, suggested_rate: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label().' — '.$type->taxLabel(),
            'tax' => $type->taxLabel(),
            'suggested_rate' => number_format($type->suggestedRateBasisPoints() / 100, 2, '.', ''),
        ], self::cases());
    }
}
