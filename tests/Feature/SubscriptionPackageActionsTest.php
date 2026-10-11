<?php

use App\Actions\DeleteSubscriptionPackage;
use App\Actions\SaveSubscriptionPackage;
use App\Exceptions\BillingActionRefused;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkspaceSubscription;
use App\SubscriptionInterval;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packageData(array $overrides = []): array
{
    return [
        'code' => 'crescer',
        'name' => 'Crescer',
        'summary' => 'Para pequenas empresas.',
        'features' => ['5 utilizadores', 'Suporte prioritário'],
        'trial_days' => 14,
        'currency_code' => 'AOA',
        'is_active' => true,
        'is_featured' => false,
        'sort_order' => 3,
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => true],
        ],
        ...$overrides,
    ];
}

function staffMember(): User
{
    return User::factory()->supportStaff()->create();
}

test('creating a package creates one plan row per period with the package data copied across', function () {
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), staffMember());

    $plans = $package->plans;

    expect($package->code)->toBe('crescer')
        ->and($plans)->toHaveCount(2)
        ->and($plans->pluck('code')->all())->toBe(['crescer-monthly', 'crescer-annual'])
        ->and($plans->pluck('interval')->all())->toBe([SubscriptionInterval::Monthly, SubscriptionInterval::Annual])
        ->and($plans->pluck('amount_minor')->all())->toBe([25_000_00, 250_000_00])
        ->and($plans->pluck('sort_order')->all())->toBe([30, 33])
        ->and($plans->every(fn (SubscriptionPlan $plan): bool => $plan->name === 'Crescer'
            && $plan->summary === 'Para pequenas empresas.'
            && $plan->features === ['5 utilizadores', 'Suporte prioritário']
            && $plan->trial_days === 14
            && $plan->currency_code === 'AOA'
            && $plan->is_active
            && $plan->subscription_package_id === $package->id))->toBeTrue();
});

test('the plans of a package come back shortest period first whatever order they were made in', function () {
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData([
        'periods' => [
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Semiannual, 'amount_minor' => 130_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Quarterly, 'amount_minor' => 70_000_00, 'is_active' => true],
        ],
    ]), staffMember());

    expect($package->plans->pluck('interval.value')->all())->toBe(['monthly', 'quarterly', 'semiannual', 'annual']);
});

test('updating keeps the plan rows and their public ids and changes price and copy in place', function () {
    $actor = staffMember();
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);
    $monthly = $package->plans->first();

    $updated = app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'code' => 'ignorado',
        'name' => 'Crescer Mais',
        'features' => ['10 utilizadores'],
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 30_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => true],
        ],
    ]), $actor);

    $fresh = $updated->plans->first();

    expect($updated->code)->toBe('crescer')
        ->and($updated->name)->toBe('Crescer Mais')
        ->and(SubscriptionPlan::query()->count())->toBe(2)
        ->and($fresh->public_id)->toBe($monthly->public_id)
        ->and($fresh->code)->toBe('crescer-monthly')
        ->and($fresh->amount_minor)->toBe(30_000_00)
        ->and($fresh->name)->toBe('Crescer Mais')
        ->and($fresh->features)->toBe(['10 utilizadores']);
});

test('a period that is switched off or dropped is deactivated, never deleted', function () {
    $actor = staffMember();
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);
    $annualId = $package->plans->last()->id;

    app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'periods' => [['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true]],
    ]), $actor);

    $annual = SubscriptionPlan::query()->findOrFail($annualId);
    expect($annual->is_active)->toBeFalse()
        ->and($annual->subscription_package_id)->toBe($package->id)
        ->and(SubscriptionPlan::query()->count())->toBe(2);

    app(SaveSubscriptionPackage::class)->execute($package, packageData(), $actor);
    expect($annual->fresh()->is_active)->toBeTrue()
        ->and($annual->fresh()->id)->toBe($annualId);

    app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => false],
        ],
    ]), $actor);
    expect($annual->fresh()->is_active)->toBeFalse();
});

test('deactivating a package deactivates every plan and activating it restores the active periods', function () {
    $actor = staffMember();
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);

    app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'is_active' => false,
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => false],
        ],
    ]), $actor);

    expect($package->plans()->where('is_active', true)->count())->toBe(0)
        ->and($package->fresh()->is_active)->toBeFalse();

    app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => false],
        ],
    ]), $actor);

    expect($package->plans()->where('is_active', true)->pluck('code')->all())->toBe(['crescer-monthly']);
});

test('an active package needs an active period, but an inactive one may have none', function () {
    $actor = staffMember();

    expect(fn () => app(SaveSubscriptionPackage::class)->execute(null, packageData([
        'periods' => [['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => false]],
    ]), $actor))->toThrow(ValidationException::class);

    expect(fn () => app(SaveSubscriptionPackage::class)->execute(null, packageData(['periods' => []]), $actor))
        ->toThrow(ValidationException::class);

    expect(SubscriptionPackage::query()->count())->toBe(0);

    $draft = app(SaveSubscriptionPackage::class)->execute(null, packageData([
        'is_active' => false,
        'periods' => [['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => false]],
    ]), $actor);

    expect($draft->plans)->toHaveCount(1);
});

test('the same interval twice and a price of zero are refused before anything is written', function () {
    $actor = staffMember();

    expect(fn () => app(SaveSubscriptionPackage::class)->execute(null, packageData([
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 25_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 26_000_00, 'is_active' => true],
        ],
    ]), $actor))->toThrow(ValidationException::class);

    expect(fn () => app(SaveSubscriptionPackage::class)->execute(null, packageData([
        'periods' => [['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 0, 'is_active' => true]],
    ]), $actor))->toThrow(ValidationException::class);

    expect(SubscriptionPackage::query()->count())->toBe(0)->and(SubscriptionPlan::query()->count())->toBe(0);
});

test('featuring a package un-features every other one', function () {
    $actor = staffMember();
    $first = app(SaveSubscriptionPackage::class)->execute(null, packageData(['is_featured' => true]), $actor);
    $second = app(SaveSubscriptionPackage::class)->execute(null, packageData(['code' => 'escala', 'name' => 'Escala', 'is_featured' => true]), $actor);

    expect($first->fresh()->is_featured)->toBeFalse()
        ->and($second->fresh()->is_featured)->toBeTrue();

    app(SaveSubscriptionPackage::class)->execute($second, packageData(['name' => 'Escala', 'is_featured' => false]), $actor);

    expect(SubscriptionPackage::query()->where('is_featured', true)->count())->toBe(0);
});

test('a plan code already taken by another plan is made unique and stays inside the column', function () {
    SubscriptionPlan::factory()->create(['code' => 'crescer-monthly']);
    $longCode = str_repeat('a', 48);
    SubscriptionPlan::factory()->create(['code' => substr($longCode.'-monthly', 0, 64)]);

    $actor = staffMember();
    $short = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);
    $long = app(SaveSubscriptionPackage::class)->execute(null, packageData(['code' => $longCode]), $actor);

    expect($short->plans->first()->code)->toBe('crescer-monthly-2')
        ->and($long->plans->first()->code)->not->toBe($longCode.'-monthly')
        ->and(strlen($long->plans->first()->code))->toBeLessThanOrEqual(64)
        ->and(SubscriptionPlan::query()->distinct()->count('code'))->toBe(SubscriptionPlan::query()->count());
});

test('a change of price is written to the activity log with who made it', function () {
    $actor = staffMember();
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);

    app(SaveSubscriptionPackage::class)->execute($package, packageData([
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 30_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => true],
        ],
    ]), $actor);

    $entry = Activity::query()->where('event', 'periods-synchronised')->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe($actor->id)
        ->and($entry->subject_id)->toBe($package->id)
        ->and($entry->getProperty('periods'))->toBe([
            'monthly' => [
                'from' => ['amount_minor' => 25_000_00, 'currency_code' => 'AOA', 'is_active' => true],
                'to' => ['amount_minor' => 30_000_00, 'currency_code' => 'AOA', 'is_active' => true],
            ],
        ]);
});

test('saving a package without changing the periods writes no price history', function () {
    $actor = staffMember();
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), $actor);
    $before = Activity::query()->where('event', 'periods-synchronised')->count();

    app(SaveSubscriptionPackage::class)->execute($package, packageData(['summary' => 'Outro texto.']), $actor);

    expect(Activity::query()->where('event', 'periods-synchronised')->count())->toBe($before);
});

test('a package that was never sold can be deleted with its plans', function () {
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), staffMember());

    app(DeleteSubscriptionPackage::class)->execute($package);

    expect(SubscriptionPackage::query()->count())->toBe(0)->and(SubscriptionPlan::query()->count())->toBe(0);
});

test('a package whose plan has a subscription is refused and left whole', function () {
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), staffMember());
    WorkspaceSubscription::factory()->create(['subscription_plan_id' => $package->plans->first()->id]);

    expect(fn () => app(DeleteSubscriptionPackage::class)->execute($package))
        ->toThrow(BillingActionRefused::class, 'Este pacote já teve subscritores. Desactive-o em vez de o apagar.');

    expect(SubscriptionPackage::query()->count())->toBe(1)->and(SubscriptionPlan::query()->count())->toBe(2);
});

test('a package whose plan has a charge is refused even when it never became a subscription', function () {
    $package = app(SaveSubscriptionPackage::class)->execute(null, packageData(), staffMember());
    SubscriptionCharge::factory()->create(['subscription_plan_id' => $package->plans->last()->id]);

    expect(fn () => app(DeleteSubscriptionPackage::class)->execute($package))
        ->toThrow(BillingActionRefused::class);

    expect(SubscriptionPackage::query()->count())->toBe(1);
});

test('the database refuses two plans of one package for the same interval', function () {
    $package = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00])->create();

    expect(fn () => DB::transaction(fn () => SubscriptionPlan::factory()->create([
        'subscription_package_id' => $package->id,
        'interval' => SubscriptionInterval::Monthly,
    ])))->toThrow(QueryException::class);

    SubscriptionPlan::factory()->count(2)->create(['subscription_package_id' => null, 'interval' => SubscriptionInterval::Monthly]);
    expect(SubscriptionPlan::query()->whereNull('subscription_package_id')->count())->toBe(2);
});

test('a package with plans cannot be removed underneath them', function () {
    $package = SubscriptionPackage::factory()->withPlans()->create();

    expect(fn () => DB::transaction(fn () => $package->delete()))->toThrow(QueryException::class);
});

test('the factory states and helper build what the screens expect', function () {
    $featured = SubscriptionPackage::factory()->featured()->create();
    $inactive = SubscriptionPackage::factory()->inactive()->withPlans(['quarterly' => 70_000_00])->create();

    expect($featured->is_featured)->toBeTrue()
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->plans)->toHaveCount(1)
        ->and($inactive->plans->first()->is_active)->toBeFalse()
        ->and($inactive->plans->first()->package->is($inactive))->toBeTrue();
});
