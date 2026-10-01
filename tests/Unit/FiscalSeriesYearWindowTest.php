<?php

use App\Fiscal\Documents\FiscalSeriesYearWindow;
use Carbon\CarbonImmutable;

test('only the current Luanda year is available through 15 December', function () {
    $window = new FiscalSeriesYearWindow;

    expect($window->allowedYears(
        CarbonImmutable::parse('2026-12-15 23:59:59', 'Africa/Luanda'),
    ))->toBe([2026]);
});

test('the following year becomes available after 15 December in Luanda', function () {
    $window = new FiscalSeriesYearWindow;

    expect($window->allowedYears(
        CarbonImmutable::parse('2026-12-16 00:00:00', 'Africa/Luanda'),
    ))->toBe([2026, 2027]);
});
