<?php

namespace App\Fiscal\Calculation;

final readonly class CalculatedFiscalDocument
{
    /**
     * @param  list<CalculatedFiscalLine>  $lines
     */
    public function __construct(
        public array $lines,
        public int $settlementTotalMinor,
        public int $netTotalMinor,
        public int $taxPayableMinor,
        public int $grossTotalMinor,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprintData(): array
    {
        return [
            'lines' => array_map(
                fn (CalculatedFiscalLine $line): array => $line->fingerprintData(),
                $this->lines,
            ),
            'settlement_total_minor' => $this->settlementTotalMinor,
            'net_total_minor' => $this->netTotalMinor,
            'tax_payable_minor' => $this->taxPayableMinor,
            'gross_total_minor' => $this->grossTotalMinor,
        ];
    }
}
