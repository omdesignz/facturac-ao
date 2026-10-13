<?php

use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkspaceSubscription;
use App\WorkspaceSubscriptionStatus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    // The screen is built separately; do not let a missing file fail the props tests.
    config(['inertia.testing.ensure_pages_exist' => file_exists(resource_path('js/pages/Support/Packages.vue'))]);
});

/** @return array<string, int> */
function packageConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packagePayload(array $overrides = []): array
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
            ['interval' => 'monthly', 'amount' => '25000.00', 'is_active' => true],
            ['interval' => 'annual', 'amount' => '250000.5', 'is_active' => true],
        ],
        ...$overrides,
    ];
}

test('staff see every package with its periods, counts and the lists the form needs', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $sold = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00, 'quarterly' => 70_000_00])->create(['sort_order' => 2, 'name' => 'Vendido']);
    $fresh = SubscriptionPackage::factory()->inactive()->withPlans(['annual' => 250_000_50])->create(['sort_order' => 1, 'name' => 'Novo']);
    $monthly = $sold->plans->first();
    WorkspaceSubscription::factory()->count(2)->create(['subscription_plan_id' => $monthly->id, 'status' => WorkspaceSubscriptionStatus::Active]);
    WorkspaceSubscription::factory()->create(['subscription_plan_id' => $monthly->id, 'status' => WorkspaceSubscriptionStatus::Cancelled]);

    $this->actingAs($staff)
        ->get(route('support.packages.index'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support/Packages')
            ->has('packages', 2)
            ->where('packages.0.public_id', $fresh->public_id)
            ->where('packages.0.is_active', false)
            ->where('packages.0.can_delete', true)
            ->where('packages.0.subscribers_count', 0)
            ->where('packages.0.periods.0.amount', '250000.50')
            ->where('packages.0.periods.0.amount_minor', 250_000_50)
            ->where('packages.1.public_id', $sold->public_id)
            ->where('packages.1.code', $sold->code)
            ->where('packages.1.features', ['Facturação electrónica', 'Suporte em português'])
            ->where('packages.1.currency_code', 'AOA')
            ->where('packages.1.subscribers_count', 2)
            ->where('packages.1.can_delete', false)
            ->has('packages.1.periods', 2)
            ->where('packages.1.periods.0', [
                'interval' => 'monthly',
                'interval_label' => 'Mensal',
                'months' => 1,
                'amount_minor' => 25_000_00,
                'amount' => '25000.00',
                'is_active' => true,
                'plan_public_id' => $monthly->public_id,
                'subscribers_count' => 2,
            ])
            ->where('packages.1.periods.1.interval', 'quarterly')
            ->where('packages.1.periods.1.months', 3)
            ->where('packages.1.periods.1.subscribers_count', 0)
            ->where('intervals', [
                ['value' => 'monthly', 'label' => 'Mensal', 'months' => 1, 'period_label' => 'por mês'],
                ['value' => 'quarterly', 'label' => 'Trimestral', 'months' => 3, 'period_label' => 'por trimestre'],
                ['value' => 'semiannual', 'label' => 'Semestral', 'months' => 6, 'period_label' => 'por semestre'],
                ['value' => 'annual', 'label' => 'Anual', 'months' => 12, 'period_label' => 'por ano'],
            ])
            ->where('currencies', config('fiscal.currencies')));
});

test('a package with only a charge on record cannot be deleted either', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00])->create();
    SubscriptionCharge::factory()->create(['subscription_plan_id' => $package->plans->first()->id]);

    $this->actingAs($staff)
        ->get(route('support.packages.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('packages.0.can_delete', false)
            ->where('packages.0.subscribers_count', 0));
});

test('staff can create a package and its plans are made', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->from(route('support.packages.index'))
        ->post(route('support.packages.store'), packagePayload(['features' => ['  5 utilizadores ', '', 'Suporte prioritário']]))
        ->assertRedirect(route('support.packages.index'))
        ->assertSessionHas('success', 'Pacote criado.')
        ->assertSessionHasNoErrors();

    $package = SubscriptionPackage::query()->sole();

    expect($package->code)->toBe('crescer')
        ->and($package->features)->toBe(['5 utilizadores', 'Suporte prioritário'])
        ->and($package->plans->pluck('amount_minor')->all())->toBe([25_000_00, 250_000_50])
        ->and($package->plans->pluck('code')->all())->toBe(['crescer-monthly', 'crescer-annual']);
});

test('staff can update a package; the code is ignored and the plan keeps its public id', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00, 'annual' => 250_000_00])->create(['code' => 'crescer']);
    $monthlyPublicId = $package->plans->first()->public_id;

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->put(route('support.packages.update', $package), packagePayload([
            'code' => 'outro-codigo',
            'name' => 'Crescer Mais',
            'periods' => [
                ['interval' => 'monthly', 'amount' => '30000', 'is_active' => true],
                ['interval' => 'annual', 'amount' => '250000', 'is_active' => false],
            ],
        ]))
        ->assertSessionHas('success', 'Pacote actualizado.')
        ->assertSessionHasNoErrors();

    $package->refresh();

    expect($package->code)->toBe('crescer')
        ->and($package->name)->toBe('Crescer Mais')
        ->and($package->plans->first()->public_id)->toBe($monthlyPublicId)
        ->and($package->plans->first()->amount_minor)->toBe(30_000_00)
        ->and($package->plans->last()->is_active)->toBeFalse();
});

test('staff can deactivate a package and its plans stop being offered', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create();

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->put(route('support.packages.update', $package), packagePayload(['is_active' => false]))
        ->assertSessionHasNoErrors();

    expect($package->fresh()->is_active)->toBeFalse()
        ->and($package->plans()->where('is_active', true)->exists())->toBeFalse();
});

test('featuring a package through the form un-features the other one', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $first = SubscriptionPackage::factory()->featured()->withPlans()->create();
    $second = SubscriptionPackage::factory()->withPlans()->create();

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->put(route('support.packages.update', $second), packagePayload(['is_featured' => true]))
        ->assertSessionHasNoErrors();

    expect($first->fresh()->is_featured)->toBeFalse()->and($second->fresh()->is_featured)->toBeTrue();
});

test('staff can delete a package that was never sold', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create();

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->delete(route('support.packages.destroy', $package))
        ->assertSessionHas('success', 'Pacote apagado.');

    expect(SubscriptionPackage::query()->count())->toBe(0)->and(SubscriptionPlan::query()->count())->toBe(0);
});

test('deleting a package that had subscribers is refused with a message a person can read', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create();
    WorkspaceSubscription::factory()->create(['subscription_plan_id' => $package->plans->first()->id]);

    $this->actingAs($staff)
        ->withSession(packageConfirmed())
        ->delete(route('support.packages.destroy', $package))
        ->assertSessionHas('error', 'Este pacote já teve subscritores. Desactive-o em vez de o apagar.');

    expect(SubscriptionPackage::query()->count())->toBe(1);
});

test('customers get the same 404 as for every other support route', function () {
    $customer = User::factory()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create();

    $this->actingAs($customer)->withSession(packageConfirmed())->get(route('support.packages.index'))->assertNotFound();
    $this->actingAs($customer)->withSession(packageConfirmed())->post(route('support.packages.store'), packagePayload())->assertNotFound();
    $this->actingAs($customer)->withSession(packageConfirmed())->put(route('support.packages.update', $package), packagePayload())->assertNotFound();
    $this->actingAs($customer)->withSession(packageConfirmed())->delete(route('support.packages.destroy', $package))->assertNotFound();

    expect(SubscriptionPackage::query()->count())->toBe(1);
});

test('writes ask for the password again and change nothing without it', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create(['name' => 'Antes']);

    $this->actingAs($staff)->post(route('support.packages.store'), packagePayload())->assertRedirect(route('password.confirm'));
    $this->actingAs($staff)->put(route('support.packages.update', $package), packagePayload())->assertRedirect(route('password.confirm'));
    $this->actingAs($staff)->delete(route('support.packages.destroy', $package))->assertRedirect(route('password.confirm'));

    expect(SubscriptionPackage::query()->count())->toBe(1)
        ->and($package->fresh()->name)->toBe('Antes')
        ->and(SubscriptionPlan::query()->count())->toBe(2);
});

test('a code that is taken, malformed, or too long is refused on create', function (string $code, string $message) {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    SubscriptionPackage::factory()->create(['code' => 'crescer']);

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['code' => $code]))
        ->assertSessionHasErrors(['code' => $message]);

    expect(SubscriptionPackage::query()->count())->toBe(1);
})->with([
    'taken' => ['crescer', 'Já existe um pacote com este código.'],
    'capitals' => ['Crescer', 'Use apenas letras minúsculas, algarismos e hífenes, sem hífen no início nem no fim.'],
    'trailing hyphen' => ['crescer-', 'Use apenas letras minúsculas, algarismos e hífenes, sem hífen no início nem no fim.'],
    'space' => ['cres cer', 'Use apenas letras minúsculas, algarismos e hífenes, sem hífen no início nem no fim.'],
    'too long' => [str_repeat('a', 49), 'O código não pode ter mais de 48 caracteres.'],
]);

test('the same interval twice is refused', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['periods' => [
            ['interval' => 'monthly', 'amount' => '25000', 'is_active' => true],
            ['interval' => 'monthly', 'amount' => '26000', 'is_active' => true],
        ]]))
        ->assertSessionHasErrors(['periods.0.interval', 'periods.1.interval']);

    expect(SubscriptionPackage::query()->count())->toBe(0);
});

test('an active package with no active period is refused on periods', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['periods' => [
            ['interval' => 'monthly', 'amount' => '25000', 'is_active' => false],
        ]]))
        ->assertSessionHasErrors(['periods' => 'Um pacote activo precisa de pelo menos um período de pagamento activo.']);

    expect(SubscriptionPackage::query()->count())->toBe(0);
});

test('a bad amount is refused', function (mixed $amount) {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['periods' => [
            ['interval' => 'monthly', 'amount' => $amount, 'is_active' => true],
        ]]))
        ->assertSessionHasErrors('periods.0.amount');

    expect(SubscriptionPackage::query()->count())->toBe(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0.00'],
    'three decimals' => ['100.001'],
    'comma' => ['100,50'],
    'negative' => ['-5'],
    'text' => ['abc'],
    'over the ceiling' => ['10000000000.00'],
    'far over the ceiling' => ['99999999999999999999'],
    'leading zero' => ['0100'],
    'empty' => [''],
]);

test('the largest price is accepted and stored exactly in minor units', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['periods' => [
            ['interval' => 'monthly', 'amount' => '9999999999.99', 'is_active' => true],
        ]]))
        ->assertSessionHasNoErrors();

    expect(SubscriptionPackage::query()->sole()->plans->sole()->amount_minor)->toBe(999_999_999_999);
});

test('too many features, duplicate features, and short or long ones are refused', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $post = fn (array $features) => $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['features' => $features]));

    $post(array_map(fn (int $n): string => "Funcionalidade {$n}", range(1, 21)))->assertSessionHasErrors(['features' => 'Um pacote pode ter no máximo 20 funcionalidades.']);
    $post(['Suporte', 'suporte'])->assertSessionHasErrors('features.1');
    $post(['A'])->assertSessionHasErrors('features.0');
    $post([str_repeat('a', 161)])->assertSessionHasErrors('features.0');
    $post(array_map(fn (int $n): string => "Funcionalidade {$n}", range(1, 20)))->assertSessionHasNoErrors();

    expect(SubscriptionPackage::query()->sole()->features)->toHaveCount(20);
});

test('the other fields keep to their limits', function (string $field, mixed $value) {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload([$field => $value]))
        ->assertSessionHasErrors($field);

    expect(SubscriptionPackage::query()->count())->toBe(0);
})->with([
    'name too short' => ['name', 'A'],
    'name too long' => ['name', str_repeat('a', 81)],
    'name missing' => ['name', null],
    'summary too long' => ['summary', str_repeat('a', 501)],
    'trial days over 90' => ['trial_days', 91],
    'trial days negative' => ['trial_days', -1],
    'currency not on the list' => ['currency_code', 'XXX'],
    'sort order over 999' => ['sort_order', 1000],
    'sort order negative' => ['sort_order', -1],
    'is_active not a boolean' => ['is_active', 'talvez'],
]);

test('between one and four periods are required', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $post = fn (array $periods) => $this->actingAs($staff)->withSession(packageConfirmed())
        ->post(route('support.packages.store'), packagePayload(['periods' => $periods]));

    $post([])->assertSessionHasErrors('periods');
    $post([
        ['interval' => 'monthly', 'amount' => '1', 'is_active' => true],
        ['interval' => 'quarterly', 'amount' => '1', 'is_active' => true],
        ['interval' => 'semiannual', 'amount' => '1', 'is_active' => true],
        ['interval' => 'annual', 'amount' => '1', 'is_active' => true],
        ['interval' => 'annual', 'amount' => '1', 'is_active' => true],
    ])->assertSessionHasErrors('periods');
    $post([['interval' => 'weekly', 'amount' => '1', 'is_active' => true]])->assertSessionHasErrors('periods.0.interval');

    expect(SubscriptionPackage::query()->count())->toBe(0);
});

test('an update does not need, and cannot change, the code', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $package = SubscriptionPackage::factory()->withPlans()->create(['code' => 'crescer']);
    $payload = packagePayload();
    unset($payload['code']);

    $this->actingAs($staff)->withSession(packageConfirmed())
        ->put(route('support.packages.update', $package), $payload)
        ->assertSessionHasNoErrors();

    expect($package->fresh()->code)->toBe('crescer');
});

test('staff feature controls update all periods and remove disabled feature promises', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $this->actingAs($staff)->withSession(packageConfirmed())->post(route('support.packages.store'), packagePayload([
        'entitlements' => ['invoicing', 'pos'],
        'features' => ['API e integrações externas', 'Suporte em português'],
    ]))->assertSessionHasNoErrors();
    $package = SubscriptionPackage::where('code', 'crescer')->firstOrFail();
    expect($package->limits['feature_pos'])->toBeTrue()->and($package->limits['feature_integrations'])->toBeFalse()
        ->and($package->features)->toContain('Ponto de venda e talões de venda', 'Suporte em português')->not->toContain('API e integrações externas');
    foreach ($package->plans as $plan) {
        expect($plan->limits)->toBe($package->limits)->and($plan->features)->toBe($package->features);
    }
});

test('feature controls reject unknown rights and cannot omit core invoicing', function (array $features) {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $this->actingAs($staff)->withSession(packageConfirmed())->postJson(route('support.packages.store'), packagePayload(['entitlements' => $features]))->assertUnprocessable();
    expect(SubscriptionPackage::count())->toBe(0);
})->with([[['invoicing', 'unknown']], [['pos']], [[]]]);
