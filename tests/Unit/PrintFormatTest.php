<?php

use App\Fiscal\Documents\PrintFormat;

test('money prints kwanza with a space between thousands and a decimal comma', function (int $minor, string $printed) {
    expect((new PrintFormat)->money($minor))->toBe($printed);
})->with([
    'zero' => [0, '0,00'],
    'cents' => [5, '0,05'],
    'hundreds' => [85_000, '850,00'],
    'thousands' => [855_000, '8 550,00'],
    'millions' => [1_368_000_000, '13 680 000,00'],
    'a credit' => [-136_850, '-1 368,50'],
]);

test('a machine decimal prints without trailing zeros or a decimal point', function (string $machine, int $minimum, string $printed) {
    expect((new PrintFormat)->decimal($machine, $minimum))->toBe($printed);
})->with([
    // "1.000 UN" on paper reads as a thousand units.
    'one unit' => ['1.000', 0, '1'],
    'a half' => ['2.500', 0, '2,5'],
    'grouped' => ['12500.250', 0, '12 500,25'],
    'a price keeps its cents' => ['2500.00', 2, '2 500,00'],
    'a price with a third place' => ['2500.125', 2, '2 500,125'],
    'below one' => ['0.750', 0, '0,75'],
    'negative' => ['-3.50', 0, '-3,5'],
]);

test('a rate prints as a whole percentage where it can', function () {
    $format = new PrintFormat;

    expect($format->percent('14.00'))->toBe('14%')
        ->and($format->percent('0.00'))->toBe('0%')
        ->and($format->percent('6.50'))->toBe('6,5%');
});
