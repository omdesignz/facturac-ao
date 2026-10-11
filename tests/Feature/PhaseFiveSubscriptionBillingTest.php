<?php

use App\Actions\ConfirmEmisPayment;
use App\Actions\SaveSubscriptionPackage;
use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceSubscription;
use App\Notifications\SubscriptionActivated;
use App\Pay4AllEnvironment;
use App\SubscriptionChargeStatus;
use App\SubscriptionInterval;
use App\WorkspaceRole;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    config()->set('billing.gateway', 'pay4all');
});

/**
 * @return array{user: User, workspace: Workspace, plan: SubscriptionPlan}
 */
function phaseFiveBillingCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $owner = User::factory()->withWorkspace('VAP Cobrança')->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('phase-five-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
            json_encode(['phase-five-recovery'], JSON_THROW_ON_ERROR),
        ),
        'two_factor_confirmed_at' => now(),
    ]);
    $workspace = $owner->currentWorkspace()->firstOrFail();

    if ($role === WorkspaceRole::Owner) {
        $user = $owner;
    } else {
        $user = User::factory()->create([
            'current_workspace_id' => $workspace->id,
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('phase-five-member-secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode(['phase-five-member-recovery'], JSON_THROW_ON_ERROR),
            ),
            'two_factor_confirmed_at' => now(),
        ]);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    $plan = SubscriptionPlan::factory()->create([
        'code' => 'crescer-mensal-'.$workspace->id,
        'name' => 'Crescer',
        'summary' => 'Facturação electrónica para pequenas empresas.',
        'amount_minor' => 25_000_00,
        'currency_code' => 'AOA',
        'interval' => SubscriptionInterval::Monthly,
        'features' => ['5 utilizadores', 'Suporte prioritário'],
        'sort_order' => 10,
    ]);

    return compact('user', 'workspace', 'plan');
}

/** @return array<string, int> */
function phaseFivePasswordSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('the billing portal exposes configurable plans and the Pay4All EMIS boundary', function () {
    $company = phaseFiveBillingCompany();

    $this->actingAs($company['user'])
        ->get(route('billing.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Show')
            ->where('plans.0.public_id', $company['plan']->public_id)
            ->where('plans.0.amount_minor', 25_000_00)
            ->where('gateway.provider', 'Pay4All é+')
            ->where('gateway.method', 'Referência EMIS')
            ->where('gateway.environment', Pay4AllEnvironment::Simulation->value)
            ->where('gateway.transaction_fee_basis_points', 100)
            ->where('canManage', true)
            ->where('subscription', null)
            ->where('activeReference', null));
});

test('checkout creates one exact EMIS charge and reuses it after repeated clicks', function () {
    $company = phaseFiveBillingCompany();

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($company['user'])
            ->withSession(phaseFivePasswordSession())
            ->post(route('billing.checkout'), [
                'plan_public_id' => $company['plan']->public_id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('billing.show'));
    }

    $charge = SubscriptionCharge::query()->firstOrFail();
    $reference = EmisPaymentReference::query()->firstOrFail();

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and(EmisPaymentReference::query()->count())->toBe(1)
        ->and($charge->amount_minor)->toBe(25_000_00)
        ->and($charge->estimated_provider_fee_minor)->toBe(25_000)
        ->and($charge->status)->toBe(SubscriptionChargeStatus::Pending)
        ->and($reference->amount_minor)->toBe($charge->amount_minor)
        ->and($reference->currency_code)->toBe('AOA')
        ->and($reference->entity)->toBe('00000')
        ->and($reference->environment)->toBe(Pay4AllEnvironment::Simulation)
        ->and($reference->status)->toBe(EmisPaymentReferenceStatus::Pending)
        ->and($reference->events()->value('event_type'))
        ->toBe(BillingPaymentEventType::ReferenceCreated)
        ->and(WorkspaceSubscription::query()->doesntExist())->toBeTrue();
});

test('a workspace cannot hold concurrent payment references for different plans', function () {
    $company = phaseFiveBillingCompany();
    $otherPlan = SubscriptionPlan::factory()->create([
        'code' => 'escala-anual-'.$company['workspace']->id,
        'amount_minor' => 250_000_00,
        'interval' => SubscriptionInterval::Annual,
    ]);

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'plan_public_id' => $company['plan']->public_id,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'plan_public_id' => $otherPlan->public_id,
        ])
        ->assertSessionHas('error', fn (string $message): bool => str_contains(
            $message,
            'Referência EMIS pendente',
        ));

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and(EmisPaymentReference::query()->count())->toBe(1)
        ->and(SubscriptionCharge::query()->firstOrFail()->subscription_plan_id)
        ->toBe($company['plan']->id);
});

test('payment confirmation is exact and idempotently activates one subscription', function () {
    Notification::fake();
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id]);
    $reference = EmisPaymentReference::query()->firstOrFail();

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($company['user'])
            ->withSession(phaseFivePasswordSession())
            ->post(route('billing.references.simulate', $reference))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('billing.show'));
    }

    $subscription = WorkspaceSubscription::query()->firstOrFail();
    $charge = SubscriptionCharge::query()->firstOrFail();

    expect(WorkspaceSubscription::query()->count())->toBe(1)
        ->and($subscription->status)->toBe(WorkspaceSubscriptionStatus::Active)
        ->and($subscription->subscription_plan_id)->toBe($company['plan']->id)
        ->and($subscription->current_period_ends_at?->isAfter($subscription->current_period_started_at))->toBeTrue()
        ->and($charge->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($charge->active_checkout_key)->toBeNull()
        ->and($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Paid)
        ->and($reference->events()->count())->toBe(3)
        ->and($reference->events()->pluck('event_type')->all())->toBe([
            BillingPaymentEventType::ReferenceCreated,
            BillingPaymentEventType::PaymentConfirmed,
            BillingPaymentEventType::SubscriptionActivated,
        ]);

    Notification::assertSentToTimes($company['user'], SubscriptionActivated::class, 1);
});

test('an amount mismatch never activates or consumes a pending reference', function () {
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id]);
    $reference = EmisPaymentReference::query()->firstOrFail();

    expect(fn () => app(ConfirmEmisPayment::class)->execute(
        paymentReference: $reference,
        providerEventId: 'pay4all-mismatch-1',
        amountMinor: $reference->amount_minor - 1,
        currencyCode: 'AOA',
        occurredAt: CarbonImmutable::now('Africa/Luanda'),
        payloadSha256: hash('sha256', 'mismatch'),
    ))->toThrow(DomainException::class, 'valor exacto');

    expect($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Pending)
        ->and(SubscriptionCharge::query()->firstOrFail()->status)->toBe(SubscriptionChargeStatus::Pending)
        ->and(WorkspaceSubscription::query()->doesntExist())->toBeTrue();
});

test('MFA and workspace management permission protect subscription changes', function () {
    $company = phaseFiveBillingCompany();
    $company['user']->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id])
        ->assertRedirect(route('settings.security'));

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue();

    $viewerCompany = phaseFiveBillingCompany(WorkspaceRole::Viewer);
    $this->actingAs($viewerCompany['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $viewerCompany['plan']->public_id])
        ->assertForbidden();
});

test('another workspace cannot discover or mutate an EMIS reference', function () {
    $ownerCompany = phaseFiveBillingCompany();
    $this->actingAs($ownerCompany['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $ownerCompany['plan']->public_id]);
    $reference = EmisPaymentReference::query()->firstOrFail();
    $otherCompany = phaseFiveBillingCompany();

    $this->actingAs($otherCompany['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.references.refresh', $reference))
        ->assertNotFound();
    $this->actingAs($otherCompany['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.references.simulate', $reference))
        ->assertNotFound();

    expect($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Pending);
});

test('expired references release the checkout lock and retain append-only evidence', function () {
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id]);
    $reference = EmisPaymentReference::query()->firstOrFail();
    $reference->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('billing:expire-emis-references')->assertSuccessful();

    expect($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Expired)
        ->and($reference->charge->fresh()->status)->toBe(SubscriptionChargeStatus::Expired)
        ->and($reference->charge->fresh()->active_checkout_key)->toBeNull()
        ->and($reference->events()->latest('id')->value('event_type'))
        ->toBe(BillingPaymentEventType::ReferenceExpired)
        ->and(fn () => $reference->events()->latest('id')->firstOrFail()->delete())
        ->toThrow(DomainException::class);
});

test('checkout expires stale evidence before issuing a replacement reference', function () {
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'plan_public_id' => $company['plan']->public_id,
        ]);
    $staleReference = EmisPaymentReference::query()->firstOrFail();
    $staleReference->forceFill(['expires_at' => now()->subMinute()])->save();
    $staleReference->charge->forceFill(['due_at' => now()->subMinute()])->save();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'plan_public_id' => $company['plan']->public_id,
        ])
        ->assertSessionHasNoErrors();

    expect(SubscriptionCharge::query()->count())->toBe(2)
        ->and(EmisPaymentReference::query()->count())->toBe(2)
        ->and($staleReference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Expired)
        ->and($staleReference->events()->latest('id')->value('event_type'))
        ->toBe(BillingPaymentEventType::ReferenceExpired)
        ->and(EmisPaymentReference::query()->latest('id')->firstOrFail()->status)
        ->toBe(EmisPaymentReferenceStatus::Pending);
});

test('non-simulation environments fail closed until the merchant API contract is installed', function () {
    config()->set('billing.pay4all.environment', Pay4AllEnvironment::Homologation->value);
    $company = phaseFiveBillingCompany();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'especificação API'));

    expect(EmisPaymentReference::query()->doesntExist())->toBeTrue()
        ->and(SubscriptionCharge::query()->firstOrFail()->status)->toBe(SubscriptionChargeStatus::Failed);
});

test('each payment period ends exactly where it always did for monthly and annual plans', function (SubscriptionInterval $interval, string $endsAt) {
    $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00:00', 'Africa/Luanda'));
    $company = phaseFiveBillingCompany();
    $company['plan']->update(['interval' => $interval]);

    $this->actingAs($company['user'])
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id])
        ->assertSessionHasNoErrors();

    expect(SubscriptionCharge::query()->sole()->period_ends_at->equalTo(CarbonImmutable::parse($endsAt, 'Africa/Luanda')))->toBeTrue();
})->with([
    'monthly from 31 January stops at the end of February' => [SubscriptionInterval::Monthly, '2027-02-28 10:00:00'],
    'annual from 31 January' => [SubscriptionInterval::Annual, '2028-01-31 10:00:00'],
]);

test('a quarterly or semi-annual plan is checked out, paid and activated for the whole period', function (SubscriptionInterval $interval, string $endsAt) {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00:00', 'Africa/Luanda'));
    $company = phaseFiveBillingCompany();
    $plan = SubscriptionPlan::factory()->create([
        'code' => $interval->value.'-'.$company['workspace']->id,
        'amount_minor' => 70_000_00,
        'interval' => $interval,
    ]);

    $this->actingAs($company['user'])
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('billing.checkout'), ['plan_public_id' => $plan->public_id])
        ->assertSessionHasNoErrors();

    $charge = SubscriptionCharge::query()->sole();
    $expectedEnd = CarbonImmutable::parse($endsAt, 'Africa/Luanda');

    expect($charge->subscription_plan_id)->toBe($plan->id)
        ->and($charge->amount_minor)->toBe(70_000_00)
        ->and($charge->period_ends_at->equalTo($expectedEnd))->toBeTrue();

    $this->actingAs($company['user'])
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('billing.references.simulate', EmisPaymentReference::query()->sole()))
        ->assertSessionHasNoErrors();

    $subscription = WorkspaceSubscription::query()->sole();

    expect($subscription->status)->toBe(WorkspaceSubscriptionStatus::Active)
        ->and($subscription->subscription_plan_id)->toBe($plan->id)
        ->and($subscription->current_period_ends_at->equalTo($expectedEnd))->toBeTrue()
        ->and($charge->fresh()->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($charge->fresh()->period_ends_at->equalTo($expectedEnd))->toBeTrue();
})->with([
    'quarterly from 31 January' => [SubscriptionInterval::Quarterly, '2027-04-30 10:00:00'],
    'semi-annual from 31 January' => [SubscriptionInterval::Semiannual, '2027-07-31 10:00:00'],
]);

test('changing a package price leaves a pending charge at the amount it was created with', function () {
    $actor = User::factory()->supportStaff()->create();
    $company = phaseFiveBillingCompany();
    $package = SubscriptionPackage::factory()->withPlans(['monthly' => 25_000_00, 'annual' => 250_000_00])->create(['code' => 'ajuste']);
    $plan = $package->plans->first();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $plan->public_id])
        ->assertSessionHasNoErrors();

    app(SaveSubscriptionPackage::class)->execute($package, [
        'name' => $package->name,
        'summary' => $package->summary,
        'features' => $package->features,
        'trial_days' => $package->trial_days,
        'currency_code' => 'AOA',
        'is_active' => true,
        'is_featured' => false,
        'sort_order' => $package->sort_order,
        'periods' => [
            ['interval' => SubscriptionInterval::Monthly, 'amount_minor' => 40_000_00, 'is_active' => true],
            ['interval' => SubscriptionInterval::Annual, 'amount_minor' => 250_000_00, 'is_active' => true],
        ],
    ], $actor);

    $charge = SubscriptionCharge::query()->sole();
    $reference = EmisPaymentReference::query()->sole();

    expect($plan->fresh()->amount_minor)->toBe(40_000_00)
        ->and($charge->amount_minor)->toBe(25_000_00)
        ->and($reference->amount_minor)->toBe(25_000_00);

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.references.simulate', $reference))
        ->assertSessionHasNoErrors();

    expect($charge->fresh()->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($charge->fresh()->amount_minor)->toBe(25_000_00)
        ->and(WorkspaceSubscription::query()->sole()->status)->toBe(WorkspaceSubscriptionStatus::Active);
});
