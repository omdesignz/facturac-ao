<?php

use App\SubscriptionInterval;
use Carbon\CarbonImmutable;

test('the intervals keep their stored values and run shortest first', function () {
    expect(SubscriptionInterval::ordered())->toBe([
        SubscriptionInterval::Monthly,
        SubscriptionInterval::Quarterly,
        SubscriptionInterval::Semiannual,
        SubscriptionInterval::Annual,
    ])
        ->and(array_map(fn (SubscriptionInterval $i): string => $i->value, SubscriptionInterval::ordered()))
        ->toBe(['monthly', 'quarterly', 'semiannual', 'annual'])
        ->and(array_map(fn (SubscriptionInterval $i): string => $i->label(), SubscriptionInterval::ordered()))
        ->toBe(['Mensal', 'Trimestral', 'Semestral', 'Anual'])
        ->and(array_map(fn (SubscriptionInterval $i): string => $i->periodLabel(), SubscriptionInterval::ordered()))
        ->toBe(['por mês', 'por trimestre', 'por semestre', 'por ano'])
        ->and(array_map(fn (SubscriptionInterval $i): int => $i->months(), SubscriptionInterval::ordered()))
        ->toBe([1, 3, 6, 12])
        ->and(array_map(fn (SubscriptionInterval $i): int => $i->position(), SubscriptionInterval::ordered()))
        ->toBe([0, 1, 2, 3]);
});

test('advance computes the end of a period without overflowing into the next month', function (string $from, string $interval, string $expected) {
    $start = CarbonImmutable::parse($from, 'Africa/Luanda');

    expect(SubscriptionInterval::from($interval)->advance($start)->toIso8601String())
        ->toBe(CarbonImmutable::parse($expected, 'Africa/Luanda')->toIso8601String());
})->with([
    'monthly from 31 January' => ['2027-01-31 10:15:30', 'monthly', '2027-02-28 10:15:30'],
    'monthly from 31 January in a leap year' => ['2028-01-31 10:15:30', 'monthly', '2028-02-29 10:15:30'],
    'monthly from 15 March' => ['2027-03-15 08:00:00', 'monthly', '2027-04-15 08:00:00'],
    'quarterly from 31 January' => ['2027-01-31 10:15:30', 'quarterly', '2027-04-30 10:15:30'],
    'quarterly from 30 November' => ['2027-11-30 10:15:30', 'quarterly', '2028-02-29 10:15:30'],
    'quarterly from 29 November' => ['2026-11-29 10:15:30', 'quarterly', '2027-02-28 10:15:30'],
    'semiannual from 31 August' => ['2027-08-31 10:15:30', 'semiannual', '2028-02-29 10:15:30'],
    'semiannual from 31 August before a common year' => ['2026-08-31 10:15:30', 'semiannual', '2027-02-28 10:15:30'],
    'semiannual from 31 January' => ['2027-01-31 10:15:30', 'semiannual', '2027-07-31 10:15:30'],
    'annual from 15 March' => ['2027-03-15 08:00:00', 'annual', '2028-03-15 08:00:00'],
    'annual from 29 February' => ['2028-02-29 08:00:00', 'annual', '2029-03-01 08:00:00'],
]);

test('monthly and annual advance exactly as the payment actions always computed them', function () {
    foreach (['2027-01-31 23:59:59', '2028-02-29 00:00:00', '2027-08-31 12:00:00', '2026-12-31 18:30:00'] as $moment) {
        $start = CarbonImmutable::parse($moment, 'Africa/Luanda');

        expect(SubscriptionInterval::Monthly->advance($start)->equalTo($start->addMonthNoOverflow()))->toBeTrue()
            ->and(SubscriptionInterval::Annual->advance($start)->equalTo($start->addYear()))->toBeTrue();
    }
});

test('no interval ends at the same moment as another, so a period identifies its interval', function () {
    foreach (['2027-01-31', '2028-02-29', '2027-05-31', '2027-08-31', '2026-11-30'] as $day) {
        $start = CarbonImmutable::parse($day.' 09:00:00', 'Africa/Luanda');
        $ends = array_map(
            fn (SubscriptionInterval $i): string => $i->advance($start)->toIso8601String(),
            SubscriptionInterval::ordered(),
        );

        expect(array_unique($ends))->toHaveCount(4);
    }
});
