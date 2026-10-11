<?php

use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentType;

/**
 * The carts in tests/Fixtures/pos-cart-cases.json are the contract between the
 * till's arithmetic and the server's. The Vue cart tests read the same file, so
 * if it drifts from what a Factura/Recibo really comes to, this fails first.
 *
 * @return list<array{name: string, lines: list<array<string, mixed>>, expected: array<string, mixed>}>
 */
function posCartCases(): array
{
    $file = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/pos-cart-cases.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $file['cases'];
}

test('the shared cart fixture holds a spread of carts', function () {
    expect(posCartCases())->toHaveCount(14)
        ->and(array_unique(array_map(
            fn (array $case): int => count($case['lines']),
            posCartCases(),
        )))->not->toHaveCount(1);
});

test('the server calculates every shared cart to the cêntimo', function (array $case) {
    $lines = array_map(function (array $line): array {
        $minor = $line['unit_price_minor'];

        return [
            'operation_type' => 'TB',
            'product_code' => 'ART',
            'product_description' => 'Artigo',
            'quantity' => $line['quantity'],
            'unit_of_measure' => 'UN',
            'unit_price' => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT),
            'discount_percentage' => $line['discount_percentage'],
            'tax_type' => $line['tax']['type'],
            'tax_code' => $line['tax']['code'],
            'tax_percentage' => $line['tax']['percentage'],
            'tax_exemption_code' => $line['tax']['exemption_code'],
        ];
    }, $case['lines']);

    $calculation = (new FiscalCalculator)->calculate($lines, FiscalDocumentType::InvoiceReceipt);
    $expected = $case['expected'];

    expect($calculation->settlementTotalMinor)->toBe($expected['settlement_total_minor'])
        ->and($calculation->netTotalMinor)->toBe($expected['net_total_minor'])
        ->and($calculation->taxPayableMinor)->toBe($expected['tax_payable_minor'])
        ->and($calculation->grossTotalMinor)->toBe($expected['gross_total_minor'])
        ->and(array_map(fn ($line): array => [
            'net_minor' => $line->netAmountMinor,
            'tax_minor' => $line->taxAmountMinor,
            'gross_minor' => $line->grossAmountMinor,
        ], $calculation->lines))->toBe($expected['lines']);
})->with(fn (): array => array_combine(
    array_column(posCartCases(), 'name'),
    array_map(fn (array $case): array => [$case], posCartCases()),
));
