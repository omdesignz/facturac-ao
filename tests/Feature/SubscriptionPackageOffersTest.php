<?php

use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Arr;

/** @var array<string, int> the monthly price and what each longer period costs, in minor units */
function offerPrices(): array
{
    return ['monthly' => 25_000_00, 'quarterly' => 70_000_00, 'semiannual' => 130_000_00, 'annual' => 250_000_00];
}

/** @return array<string, mixed> */
function billingProps(): array
{
    return test()->actingAs(billingViewer())->get(route('billing.show'))->assertOk()->inertiaProps();
}

function billingViewer(): User
{
    return User::factory()->withWorkspace('Ofertas')->create();
}

test('billing lists a package with its periods, equivalents and savings, shortest first', function () {
    $package = SubscriptionPackage::factory()->featured()->withPlans(array_reverse(offerPrices(), true))->create([
        'name' => 'Crescer',
        'summary' => 'Para crescer.',
        'trial_days' => 14,
        'features' => ['Uma', 'Duas'],
    ]);

    $props = billingProps();
    $plans = collect($props['packages'][0]['plans']);

    expect($props['plans'])->toHaveCount(4)
        ->and($props['packages'])->toHaveCount(1)
        ->and(Arr::except($props['packages'][0], 'plans'))->toBe([
            'public_id' => $package->public_id,
            'name' => 'Crescer',
            'summary' => 'Para crescer.',
            'features' => ['Uma', 'Duas'],
            'trial_days' => 14,
            'is_featured' => true,
        ])
        ->and($plans->pluck('interval')->all())->toBe(['monthly', 'quarterly', 'semiannual', 'annual'])
        ->and($plans->pluck('months')->all())->toBe([1, 3, 6, 12])
        ->and($plans->pluck('period_label')->all())->toBe(['por mês', 'por trimestre', 'por semestre', 'por ano'])
        ->and($plans->pluck('amount_minor')->all())->toBe([2_500_000, 7_000_000, 13_000_000, 25_000_000])
        ->and($plans->pluck('monthly_equivalent_minor')->all())->toBe([2_500_000, 2_333_333, 2_166_666, 2_083_333])
        // 75 000 -> 70 000 saves 6.66 %; 150 000 -> 130 000 saves 13.33 %; 300 000 -> 250 000 saves 16.66 %.
        ->and($plans->pluck('saving_percentage')->all())->toBe([null, 6, 13, 16])
        ->and($plans->first())->toHaveKeys(['public_id', 'code', 'name', 'summary', 'amount_minor', 'currency_code', 'interval', 'interval_label', 'trial_days', 'features', 'limits'])
        ->and($plans->first()['public_id'])->toBe($package->plans->first()->public_id)
        ->and($plans->first()['interval_label'])->toBe('Mensal');
});

test('a plan that costs as much as paying monthly, or more, shows no saving', function () {
    SubscriptionPackage::factory()->withPlans([
        'monthly' => 10_000_00,
        'quarterly' => 30_000_00,
        'annual' => 130_000_00,
    ])->create();

    expect(collect(billingProps()['packages'][0]['plans'])->pluck('saving_percentage')->all())->toBe([null, null, null]);
});

test('a saving below one whole percent is not shown', function () {
    SubscriptionPackage::factory()->withPlans(['monthly' => 10_000_00, 'quarterly' => 2_999_999])->create();

    expect(collect(billingProps()['packages'][0]['plans'])->pluck('saving_percentage')->all())->toBe([null, null]);
});

test('without an active monthly plan there is nothing to measure a saving against', function () {
    $package = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00, 'annual' => 250_000_00])->create();
    $package->plans()->where('interval', 'monthly')->update(['is_active' => false]);

    $plans = billingProps()['packages'][0]['plans'];

    expect($plans)->toHaveCount(1)
        ->and($plans[0]['interval'])->toBe('annual')
        ->and($plans[0]['saving_percentage'])->toBeNull();
});

test('only active packages with an active plan are offered, and plans without a package stay in plans', function () {
    SubscriptionPackage::factory()->inactive()->withPlans()->create();
    $noActivePlan = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00])->create();
    $noActivePlan->plans()->update(['is_active' => false]);
    $visible = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00])->create(['name' => 'Visível']);
    $loose = SubscriptionPlan::factory()->create(['subscription_package_id' => null]);

    $props = billingProps();

    expect($props['packages'])->toHaveCount(1)
        ->and($props['packages'][0]['name'])->toBe('Visível')
        ->and(collect($props['plans'])->pluck('public_id')->sort()->values()->all())
        ->toBe(collect([$visible->plans->first()->public_id, $loose->public_id])->sort()->values()->all());
});

test('packages are offered in the order staff gave them', function () {
    SubscriptionPackage::factory()->withPlans(['monthly' => 1_000_00])->create(['name' => 'Segundo', 'sort_order' => 20]);
    SubscriptionPackage::factory()->withPlans(['monthly' => 1_000_00])->create(['name' => 'Primeiro', 'sort_order' => 10]);

    expect(collect(billingProps()['packages'])->pluck('name')->all())->toBe(['Primeiro', 'Segundo']);
});

test('the landing page carries the packages with formatted prices and keeps plans as they were', function () {
    SubscriptionPackage::factory()->featured()->withPlans(offerPrices())->create([
        'name' => 'Crescer',
        'summary' => 'Para crescer.',
        'trial_days' => 14,
        'features' => ['Uma', 'Duas'],
    ]);
    SubscriptionPackage::factory()->inactive()->withPlans()->create();

    $props = $this->get('/')->assertOk()->inertiaProps();
    $prices = collect($props['packages'][0]['prices']);

    expect($props['plans'])->toHaveCount(4)
        ->and($props['plans'][0]['interval'])->toBe('Mensal')
        ->and($props['packages'])->toHaveCount(1)
        ->and(Arr::except($props['packages'][0], 'prices'))->toBe([
            'name' => 'Crescer',
            'summary' => 'Para crescer.',
            'features' => ['Uma', 'Duas'],
            'trial_days' => 14,
            'is_featured' => true,
        ])
        ->and($prices->pluck('interval')->all())->toBe(['monthly', 'quarterly', 'semiannual', 'annual'])
        ->and($prices->pluck('interval_label')->all())->toBe(['Mensal', 'Trimestral', 'Semestral', 'Anual'])
        ->and($prices->pluck('period_label')->all())->toBe(['por mês', 'por trimestre', 'por semestre', 'por ano'])
        ->and($prices->pluck('months')->all())->toBe([1, 3, 6, 12])
        ->and($prices->pluck('amount')->all())->toBe(['25 000', '70 000', '130 000', '250 000'])
        ->and($prices->pluck('currency')->all())->toBe(['AOA', 'AOA', 'AOA', 'AOA'])
        ->and($prices->pluck('monthly_equivalent')->all())->toBe(['25 000', '23 333', '21 667', '20 833'])
        ->and($prices->pluck('saving_percentage')->all())->toBe([null, 6, 13, 16]);
});

test('the landing page offers no packages when none is on sale', function () {
    expect($this->get('/')->assertOk()->inertiaProps('packages'))->toBe([]);
});
