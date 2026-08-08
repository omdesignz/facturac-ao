<?php

namespace App\Fiscal\Calculation;

use InvalidArgumentException;

final class FiscalCalculator
{
    /**
     * @param  list<array{
     *     operation_type: string,
     *     product_code: string,
     *     product_description: string,
     *     quantity: string,
     *     unit_of_measure: string,
     *     unit_price: string,
     *     discount_percentage: string,
     *     tax_type: string,
     *     tax_code: string|null,
     *     tax_percentage: string,
     *     tax_exemption_code: string|null
     * }>  $lines
     */
    public function calculate(array $lines): CalculatedFiscalDocument
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A fiscal document requires at least one line.');
        }

        $calculatedLines = [];
        $settlementTotalMinor = 0;
        $netTotalMinor = 0;
        $taxPayableMinor = 0;
        $grossTotalMinor = 0;

        foreach ($lines as $index => $line) {
            $quantityUnits = $this->parseDecimal($line['quantity'], 4, 'quantity');
            $unitPriceBaseMinor = $this->parseDecimal($line['unit_price'], 2, 'unit price');
            $discountRateBasisPoints = $this->parseDecimal(
                $line['discount_percentage'],
                2,
                'discount percentage',
            );
            $taxRateBasisPoints = $this->parseDecimal(
                $line['tax_percentage'],
                2,
                'tax percentage',
            );

            if ($quantityUnits <= 0) {
                throw new InvalidArgumentException('The quantity must be greater than zero.');
            }

            if ($discountRateBasisPoints > 10_000) {
                throw new InvalidArgumentException('The discount percentage cannot exceed 100.');
            }

            $baseAmountMinor = $this->roundHalfUpProduct(
                [$unitPriceBaseMinor, $quantityUnits],
                10_000,
            );
            $unitPriceMicros = $this->checkedMultiply(
                $unitPriceBaseMinor,
                10_000 - $discountRateBasisPoints,
            );
            $netAmountMinor = $this->roundHalfUpProduct(
                [$unitPriceBaseMinor, 10_000 - $discountRateBasisPoints, $quantityUnits],
                100_000_000,
            );
            $settlementAmountMinor = $baseAmountMinor - $netAmountMinor;
            $taxAmountMinor = $this->ceilProduct(
                [$netAmountMinor, $taxRateBasisPoints],
                10_000,
            );
            $grossAmountMinor = $this->checkedAdd($netAmountMinor, $taxAmountMinor);
            $tax = [
                'tax_type' => $line['tax_type'],
                'tax_country_region' => 'AO',
                'tax_code' => $line['tax_code'],
                'tax_rate_basis_points' => $taxRateBasisPoints,
                'tax_contribution_minor' => $taxAmountMinor,
                'tax_exemption_code' => $line['tax_exemption_code'],
            ];

            $calculatedLines[] = new CalculatedFiscalLine(
                lineNumber: $index + 1,
                operationType: $line['operation_type'],
                productCode: $line['product_code'],
                productDescription: $line['product_description'],
                quantityUnits: $quantityUnits,
                quantityScale: 4,
                unitOfMeasure: $line['unit_of_measure'],
                unitPriceBaseMinor: $unitPriceBaseMinor,
                unitPriceMicros: $unitPriceMicros,
                discountRateBasisPoints: $discountRateBasisPoints,
                baseAmountMinor: $baseAmountMinor,
                settlementAmountMinor: $settlementAmountMinor,
                netAmountMinor: $netAmountMinor,
                taxAmountMinor: $taxAmountMinor,
                grossAmountMinor: $grossAmountMinor,
                tax: $tax,
            );

            $settlementTotalMinor = $this->checkedAdd($settlementTotalMinor, $settlementAmountMinor);
            $netTotalMinor = $this->checkedAdd($netTotalMinor, $netAmountMinor);
            $taxPayableMinor = $this->checkedAdd($taxPayableMinor, $taxAmountMinor);
            $grossTotalMinor = $this->checkedAdd($grossTotalMinor, $grossAmountMinor);
        }

        return new CalculatedFiscalDocument(
            lines: $calculatedLines,
            settlementTotalMinor: $settlementTotalMinor,
            netTotalMinor: $netTotalMinor,
            taxPayableMinor: $taxPayableMinor,
            grossTotalMinor: $grossTotalMinor,
        );
    }

    public function parseDecimal(string $value, int $scale, string $field = 'value'): int
    {
        $value = trim($value);

        if ($scale < 0 || $scale > 9) {
            throw new InvalidArgumentException('Unsupported decimal scale.');
        }

        $pattern = $scale === 0
            ? '/\A(0|[1-9]\d*)\z/'
            : '/\A(0|[1-9]\d*)(?:\.(\d{1,'.$scale.'}))?\z/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw new InvalidArgumentException("The {$field} must be a non-negative decimal with at most {$scale} places.");
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        $factor = 10 ** $scale;

        if ($whole > intdiv(PHP_INT_MAX - (int) $fraction, $factor)) {
            throw new InvalidArgumentException("The {$field} is too large.");
        }

        return ($whole * $factor) + (int) $fraction;
    }

    /**
     * What the buyer keeps back, on whichever figure the rate is charged on.
     *
     * Separate from the line arithmetic because withholding is charged on the
     * finished document, not on each line — rounding each line and adding up
     * would produce a different figure from the one the buyer will declare.
     */
    public function withheldAmount(int $baseMinor, int $rateBasisPoints): int
    {
        if ($rateBasisPoints > 10_000) {
            throw new InvalidArgumentException('A withholding rate cannot exceed 100%.');
        }

        return $this->roundHalfUpProduct([$baseMinor, $rateBasisPoints], 10_000);
    }

    /**
     * An amount in the document's currency, restated in kwanzas.
     *
     * The rate is scaled by a million, so a document written in its own
     * currency converts through a factor of exactly one and comes back
     * unchanged rather than merely close.
     */
    public function convertedAmount(int $minor, int $exchangeRateMicro): int
    {
        if ($exchangeRateMicro < 1) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }

        return $this->roundHalfUpProduct([$minor, $exchangeRateMicro], 1_000_000);
    }

    /** @param list<int> $factors */
    private function roundHalfUpProduct(array $factors, int $denominator): int
    {
        [$numerator, $reducedDenominator] = $this->reducedProduct($factors, $denominator);
        $half = intdiv($reducedDenominator, 2);

        return intdiv($this->checkedAdd($numerator, $half), $reducedDenominator);
    }

    /** @param list<int> $factors */
    private function ceilProduct(array $factors, int $denominator): int
    {
        [$numerator, $reducedDenominator] = $this->reducedProduct($factors, $denominator);

        if ($numerator === 0) {
            return 0;
        }

        return intdiv(
            $this->checkedAdd($numerator, $reducedDenominator - 1),
            $reducedDenominator,
        );
    }

    /**
     * @param  list<int>  $factors
     * @return array{int, int}
     */
    private function reducedProduct(array $factors, int $denominator): array
    {
        foreach ($factors as $index => $factor) {
            if ($factor < 0) {
                throw new InvalidArgumentException('Fiscal calculations cannot use negative factors.');
            }

            $divisor = $this->greatestCommonDivisor($factor, $denominator);
            $factors[$index] = intdiv($factor, $divisor);
            $denominator = intdiv($denominator, $divisor);
        }

        $numerator = 1;

        foreach ($factors as $factor) {
            $numerator = $this->checkedMultiply($numerator, $factor);
        }

        return [$numerator, $denominator];
    }

    private function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return max(1, abs($left));
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new InvalidArgumentException('The fiscal calculation exceeds the supported amount.');
        }

        return $left * $right;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new InvalidArgumentException('The fiscal calculation exceeds the supported amount.');
        }

        return $left + $right;
    }
}
