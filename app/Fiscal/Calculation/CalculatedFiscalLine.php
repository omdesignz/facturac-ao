<?php

namespace App\Fiscal\Calculation;

final readonly class CalculatedFiscalLine
{
    /**
     * @param  array<string, int|string|null>  $tax
     */
    public function __construct(
        public int $lineNumber,
        public string $operationType,
        public string $productCode,
        public string $productDescription,
        public int $quantityUnits,
        public int $quantityScale,
        public string $unitOfMeasure,
        public int $unitPriceBaseMinor,
        public int $unitPriceMicros,
        public int $discountRateBasisPoints,
        public int $baseAmountMinor,
        public int $settlementAmountMinor,
        public int $netAmountMinor,
        public int $taxAmountMinor,
        public int $grossAmountMinor,
        public array $tax,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprintData(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'operation_type' => $this->operationType,
            'product_code' => $this->productCode,
            'product_description' => $this->productDescription,
            'quantity_units' => $this->quantityUnits,
            'quantity_scale' => $this->quantityScale,
            'unit_of_measure' => $this->unitOfMeasure,
            'unit_price_base_minor' => $this->unitPriceBaseMinor,
            'unit_price_micros' => $this->unitPriceMicros,
            'discount_rate_basis_points' => $this->discountRateBasisPoints,
            'base_amount_minor' => $this->baseAmountMinor,
            'settlement_amount_minor' => $this->settlementAmountMinor,
            'net_amount_minor' => $this->netAmountMinor,
            'tax_amount_minor' => $this->taxAmountMinor,
            'gross_amount_minor' => $this->grossAmountMinor,
            'tax' => $this->tax,
        ];
    }
}
