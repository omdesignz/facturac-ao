<?php

use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;

/** @return array<string, string|null> */
function fiscalCalculationLine(array $overrides = []): array
{
    return [
        'operation_type' => 'TB',
        'product_code' => 'ART-001',
        'product_description' => 'Produto de teste',
        'quantity' => '2.5',
        'unit_of_measure' => 'un',
        'unit_price' => '100.00',
        'discount_percentage' => '10',
        'tax_type' => 'IVA',
        'tax_code' => 'NOR',
        'tax_percentage' => '14',
        'tax_exemption_code' => null,
        ...$overrides,
    ];
}

test('it calculates exact integer minor-unit totals without floating point', function () {
    $calculation = (new FiscalCalculator)->calculate([fiscalCalculationLine()]);
    $line = $calculation->lines[0];

    expect($line->quantityUnits)->toBe(25_000)
        ->and($line->unitPriceBaseMinor)->toBe(10_000)
        ->and($line->unitPriceMicros)->toBe(90_000_000)
        ->and($line->baseAmountMinor)->toBe(25_000)
        ->and($line->settlementAmountMinor)->toBe(2_500)
        ->and($line->netAmountMinor)->toBe(22_500)
        ->and($line->taxAmountMinor)->toBe(3_150)
        ->and($line->grossAmountMinor)->toBe(25_650)
        ->and($calculation->settlementTotalMinor)->toBe(2_500)
        ->and($calculation->netTotalMinor)->toBe(22_500)
        ->and($calculation->taxPayableMinor)->toBe(3_150)
        ->and($calculation->grossTotalMinor)->toBe(25_650);
});

test('tax contribution is always rounded upward to the next cent', function () {
    $calculation = (new FiscalCalculator)->calculate([
        fiscalCalculationLine([
            'quantity' => '1',
            'unit_price' => '0.01',
            'discount_percentage' => '0',
        ]),
        fiscalCalculationLine([
            'product_code' => 'ART-002',
            'quantity' => '1',
            'unit_price' => '165.31',
            'discount_percentage' => '0',
        ]),
    ]);

    expect($calculation->lines[0]->taxAmountMinor)->toBe(1)
        ->and($calculation->lines[1]->taxAmountMinor)->toBe(2_315)
        ->and($calculation->taxPayableMinor)->toBe(2_316);
});

test('it retains four decimal quantity precision and aggregates mixed treatments', function () {
    $calculation = (new FiscalCalculator)->calculate([
        fiscalCalculationLine([
            'quantity' => '1.2345',
            'unit_price' => '10.00',
            'discount_percentage' => '0',
        ]),
        fiscalCalculationLine([
            'product_code' => 'SERV-001',
            'operation_type' => 'SG',
            'quantity' => '2',
            'unit_price' => '50.00',
            'discount_percentage' => '5',
            'tax_type' => 'NS',
            'tax_code' => null,
            'tax_percentage' => '0',
            'tax_exemption_code' => 'M02',
        ]),
    ]);

    expect($calculation->lines[0]->netAmountMinor)->toBe(1_235)
        ->and($calculation->lines[0]->taxAmountMinor)->toBe(173)
        ->and($calculation->lines[1]->netAmountMinor)->toBe(9_500)
        ->and($calculation->lines[1]->taxAmountMinor)->toBe(0)
        ->and($calculation->netTotalMinor)->toBe(10_735)
        ->and($calculation->grossTotalMinor)->toBe(10_908);
});

test('it rejects invalid precision and discounts above one hundred percent', function (array $overrides) {
    (new FiscalCalculator)->calculate([fiscalCalculationLine($overrides)]);
})->with([
    'quantity precision' => [['quantity' => '1.00001']],
    'money precision' => [['unit_price' => '2.999']],
    'discount ceiling' => [['discount_percentage' => '100.01']],
])->throws(InvalidArgumentException::class);

test('canonical fiscal json rejects floating point values', function () {
    (new CanonicalJson)->encode(['amount' => 1.2]);
})->throws(InvalidArgumentException::class);

test('canonical numbers remove insignificant zeroes without losing scale', function () {
    expect((string) CanonicalNumber::fromMinorUnits(10_000))->toBe('100')
        ->and((string) CanonicalNumber::fromMinorUnits(10_050))->toBe('100.5')
        ->and((string) CanonicalNumber::fromScaledInteger(12_345, 4))->toBe('1.2345');
});
