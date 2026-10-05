<?php

namespace App\Fiscal\Documents;

/**
 * Figures as an Angolan reader expects them on paper: a space between
 * thousands and a comma before the decimals.
 *
 * The presenters hand machine decimals to the sheet ("1.000", "14.00") because
 * the same payload feeds the web view and the tests. Printed raw, "1.000 UN"
 * reads as a thousand units; every figure on a PDF goes through here instead.
 * All string arithmetic, so a quantity is never rounded through a float.
 */
class PrintFormat
{
    /** Kwanza and cêntimos: always two decimals. */
    public function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.$this->group((string) intdiv($minor, 100)).','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A machine decimal ("1250.500") for print ("1 250,5"): trailing zeros
     * dropped, but never fewer than $minimumDecimals places.
     */
    public function decimal(string $value, int $minimumDecimals = 0): string
    {
        $value = trim($value);
        $sign = str_starts_with($value, '-') ? '-' : '';
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-+'), 2), 2, '');

        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $minimumDecimals, '0');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');

        return $sign.$this->group($whole).($fraction === '' ? '' : ','.$fraction);
    }

    /** A rate held as a machine decimal ("14.00") as printed ("14%"). */
    public function percent(string $value): string
    {
        return $this->decimal($value).'%';
    }

    private function group(string $digits): string
    {
        return ltrim(strrev(chunk_split(strrev($digits), 3, ' ')), ' ');
    }
}
