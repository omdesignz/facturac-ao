<?php

use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function packagesMigration(): Migration
{
    return require database_path('migrations/2026_10_11_100000_create_subscription_packages_table.php');
}

/** Inserts a plan the way it existed before packages: no package. */
function legacyPlan(array $attributes = []): int
{
    return DB::table('subscription_plans')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'code' => 'legado-mensal',
        'name' => 'Legado',
        'summary' => 'Plano anterior.',
        'amount_minor' => 10_000_00,
        'currency_code' => 'AOA',
        'interval' => 'monthly',
        'trial_days' => 7,
        'features' => json_encode(['Uma', 'Duas']),
        'limits' => json_encode(['users' => 3]),
        'is_active' => true,
        'sort_order' => 5,
        'subscription_package_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

test('the backfill gives every plan without a package a package made from it', function () {
    $monthly = legacyPlan();
    $annual = legacyPlan(['code' => 'legado-anual', 'interval' => 'annual', 'amount_minor' => 100_000_00, 'is_active' => false, 'sort_order' => 6]);
    $linked = SubscriptionPackage::factory()->withPlans(['monthly' => 1_000_00])->create();
    $alreadyLinkedId = $linked->plans->first()->id;

    packagesMigration()->backfillPackages();

    $monthlyPackage = SubscriptionPlan::query()->findOrFail($monthly)->package;
    $annualPackage = SubscriptionPlan::query()->findOrFail($annual)->package;

    expect(SubscriptionPackage::query()->count())->toBe(3)
        ->and(SubscriptionPlan::query()->whereNull('subscription_package_id')->count())->toBe(0)
        ->and($monthlyPackage->code)->toBe('legado-mensal')
        ->and($monthlyPackage->name)->toBe('Legado')
        ->and($monthlyPackage->summary)->toBe('Plano anterior.')
        ->and($monthlyPackage->features)->toBe(['Uma', 'Duas'])
        ->and($monthlyPackage->limits)->toBe(['users' => 3])
        ->and($monthlyPackage->trial_days)->toBe(7)
        ->and($monthlyPackage->currency_code)->toBe('AOA')
        ->and($monthlyPackage->is_active)->toBeTrue()
        ->and($monthlyPackage->is_featured)->toBeFalse()
        ->and($monthlyPackage->sort_order)->toBe(5)
        ->and($monthlyPackage->public_id)->toHaveLength(26)
        ->and($annualPackage->code)->toBe('legado-anual')
        ->and($annualPackage->is_active)->toBeFalse()
        ->and(SubscriptionPlan::query()->findOrFail($alreadyLinkedId)->subscription_package_id)->toBe($linked->id);
});

test('the backfill leaves each plan exactly as it was and is safe to run twice', function () {
    $id = legacyPlan();
    $before = (array) DB::table('subscription_plans')->where('id', $id)->first();

    packagesMigration()->backfillPackages();
    packagesMigration()->backfillPackages();

    $after = (array) DB::table('subscription_plans')->where('id', $id)->first();

    expect(array_diff_assoc($after, $before))->toHaveKey('subscription_package_id')->toHaveCount(1)
        ->and(SubscriptionPackage::query()->count())->toBe(1);
});

test('the backfill fits and de-duplicates codes that are not valid package codes', function () {
    legacyPlan(['code' => 'Plano Básico!!']);
    legacyPlan(['code' => 'plano-basico-']);
    legacyPlan(['code' => str_repeat('x', 64)]);
    legacyPlan(['code' => str_repeat('x', 63).'y']);
    legacyPlan(['code' => '---']);

    packagesMigration()->backfillPackages();

    $codes = SubscriptionPackage::query()->orderBy('id')->pluck('code')->all();

    expect($codes)->toHaveCount(5)
        ->and(array_unique($codes))->toHaveCount(5)
        ->and($codes[0])->toBe('plano-b-sico')
        ->and($codes[1])->toBe('plano-basico')
        ->and(collect($codes)->every(fn (string $code): bool => strlen($code) <= 48
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $code) === 1))->toBeTrue()
        ->and($codes[4])->toBe('pacote');
});

test('the migration reverses cleanly and runs again with the plans linked', function () {
    $id = legacyPlan();
    packagesMigration()->backfillPackages();

    $migration = packagesMigration();
    $migration->down();

    expect(Schema::hasTable('subscription_packages'))->toBeFalse()
        ->and(Schema::hasColumn('subscription_plans', 'subscription_package_id'))->toBeFalse()
        ->and(DB::table('subscription_plans')->where('id', $id)->exists())->toBeTrue();

    $migration->up();

    $plan = DB::table('subscription_plans')->where('id', $id)->first();

    expect(Schema::hasTable('subscription_packages'))->toBeTrue()
        ->and($plan->subscription_package_id)->not->toBeNull()
        ->and(DB::table('subscription_packages')->count())->toBe(1);
});
