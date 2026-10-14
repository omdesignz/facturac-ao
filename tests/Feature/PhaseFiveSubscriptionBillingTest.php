<?php

use App\Actions\FulfillHostedPayment;
use App\Actions\SaveSubscriptionPackage;
use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceSubscription;
use App\Notifications\SubscriptionActivated;
use App\PaymentEnvironment;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use App\SubscriptionInterval;
use App\WorkspaceRole;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;

require_once __DIR__.'/../HostedBillingFixtures.php';

beforeEach(function (): void {
    configureHostedBilling();
    fakeHostedProvider();
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

test('the billing portal exposes configurable plans and the WiPay payment boundary', function () {
    $company = phaseFiveBillingCompany();

    $this->actingAs($company['user'])
        ->get(route('billing.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Show')
            ->where('plans.0.public_id', $company['plan']->public_id)
            ->where('plans.0.amount_minor', 25_000_00)
            ->where('gateway.provider', 'WiPay')
            ->where('gateway.method', 'Pagamento online')
            ->where('gateway.environment', PaymentEnvironment::Sandbox->value)
            ->where('canManage', true)
            ->where('subscription', null)
            ->missing('activeReference')->where('activePayment', null));
});

test('checkout creates one exact WiPay charge and reuses it after repeated clicks', function () {
    $company = phaseFiveBillingCompany();

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($company['user'])
            ->withSession(phaseFivePasswordSession())
            ->post(route('billing.checkout'), [
                'customer_phone' => '900000000',
                'plan_public_id' => $company['plan']->public_id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirectContains('https://hosted.wipay.ao/');
    }

    $charge = SubscriptionCharge::query()->firstOrFail();
    $reference = Payment::query()->firstOrFail();

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and($charge->amount_minor)->toBe(25_000_00)
        ->and($charge->estimated_provider_fee_minor)->toBe(0)
        ->and($charge->status)->toBe(SubscriptionChargeStatus::Pending)
        ->and($reference->amount_minor)->toBe($charge->amount_minor)
        ->and($reference->currency_code)->toBe('AOA')
        ->and($reference->environment)->toBe(PaymentEnvironment::Sandbox)
        ->and($reference->status)->toBe(PaymentStatus::Pending)
        ->and(WorkspaceSubscription::query()->doesntExist())->toBeTrue();
});

test('a workspace cannot hold concurrent payments for different plans', function () {
    $company = phaseFiveBillingCompany();
    $otherPlan = SubscriptionPlan::factory()->create([
        'code' => 'escala-anual-'.$company['workspace']->id,
        'amount_minor' => 250_000_00,
        'interval' => SubscriptionInterval::Annual,
    ]);

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'customer_phone' => '900000000',
            'plan_public_id' => $company['plan']->public_id,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), [
            'customer_phone' => '900000000',
            'plan_public_id' => $otherPlan->public_id,
        ])
        ->assertSessionHas('error', fn (string $message): bool => str_contains(
            $message,
            'pagamento pendente',
        ));

    expect(SubscriptionCharge::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(SubscriptionCharge::query()->firstOrFail()->subscription_plan_id)
        ->toBe($company['plan']->id);
});

test('payment confirmation is exact and idempotently activates one subscription', function () {
    Notification::fake();
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $company['plan']->public_id]);
    $reference = Payment::query()->firstOrFail();

    foreach (range(1, 2) as $attempt) {
        sendHostedCallback(hostedCallback($reference))->assertAccepted();
        app(FulfillHostedPayment::class)->execute($reference);
    }

    $subscription = WorkspaceSubscription::query()->firstOrFail();
    $charge = SubscriptionCharge::query()->firstOrFail();

    expect(WorkspaceSubscription::query()->count())->toBe(1)
        ->and($subscription->status)->toBe(WorkspaceSubscriptionStatus::Active)
        ->and($subscription->subscription_plan_id)->toBe($company['plan']->id)
        ->and($subscription->current_period_ends_at?->isAfter($subscription->current_period_started_at))->toBeTrue()
        ->and($charge->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($charge->active_checkout_key)->toBeNull()
        ->and($reference->fresh()->status)->toBe(PaymentStatus::Paid);

    Notification::assertSentToTimes($company['user'], SubscriptionActivated::class, 1);
});

test('an amount mismatch never activates or consumes a pending reference', function () {
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $company['plan']->public_id]);
    $reference = Payment::query()->firstOrFail();

    sendHostedCallback(hostedCallback($reference, ['amount' => '24999.99']))->assertUnprocessable();
    expect($reference->fresh()->status)->toBe(PaymentStatus::Review)
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
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $company['plan']->public_id])
        ->assertRedirect(route('settings.security'));

    expect(SubscriptionCharge::query()->doesntExist())->toBeTrue();

    $viewerCompany = phaseFiveBillingCompany(WorkspaceRole::Viewer);
    $this->actingAs($viewerCompany['user'])
        ->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $viewerCompany['plan']->public_id])
        ->assertForbidden();
});

test('another workspace cannot discover or resume a WiPay payment', function () {
    $owner = phaseFiveBillingCompany();
    $payment = startHostedTestPayment($owner);
    $other = phaseFiveBillingCompany();
    $this->actingAs($other['user'])->withSession(phaseFivePasswordSession())
        ->post(route('billing.payments.resume', $payment))->assertNotFound();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('retiring a simulated checkout releases its lock and preserves append-only history', function () {
    $company = phaseFiveBillingCompany();
    $charge = SubscriptionCharge::factory()->create([
        'workspace_id' => $company['workspace']->id, 'subscription_plan_id' => $company['plan']->id,
        'active_checkout_key' => (string) $company['workspace']->id,
    ]);
    $reference = EmisPaymentReference::factory()->create(['subscription_charge_id' => $charge->id]);
    foreach (range(1, 2) as $attempt) {
        startHostedTestPayment($company);
    }
    expect($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Cancelled)
        ->and($charge->fresh()->status)->toBe(SubscriptionChargeStatus::Cancelled)
        ->and($charge->fresh()->active_checkout_key)->toBeNull()
        ->and($reference->events()->count())->toBe(1)
        ->and($reference->events()->sole()->event_type)->toBe(BillingPaymentEventType::ReferenceCancelled)
        ->and(fn () => $reference->events()->sole()->delete())->toThrow(DomainException::class)
        ->and(Payment::count())->toBe(1);
    $this->actingAs($company['user'])->get(route('billing.show'))->assertInertia(fn (Assert $page) => $page
        ->has('charges', 2)->where('charges.1.reference.public_id', $reference->public_id));
});

test('retired reference routes cannot confirm or refresh even existing records', function () {
    $company = phaseFiveBillingCompany();
    $charge = SubscriptionCharge::factory()->create(['workspace_id' => $company['workspace']->id]);
    $reference = EmisPaymentReference::factory()->create(['subscription_charge_id' => $charge->id]);
    foreach (['refresh', 'simulate'] as $action) {
        $this->actingAs($company['user'])->withSession(phaseFivePasswordSession())
            ->post('/settings/billing/references/'.$reference->public_id.'/'.$action)->assertNotFound();
    }
    expect($reference->fresh()->status)->toBe(EmisPaymentReferenceStatus::Pending)
        ->and(WorkspaceSubscription::count())->toBe(0);
});

test('missing WiPay credentials fail closed without creating a checkout', function () {
    config()->set('billing.wipay.client_secret', '');
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id, 'customer_phone' => '900000000'])
        ->assertSessionHas('error');
    expect(Payment::count())->toBe(0)->and(SubscriptionCharge::count())->toBe(0);
});

test('each payment period ends exactly where it always did for monthly and annual plans', function (SubscriptionInterval $interval, string $endsAt) {
    $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00:00', 'Africa/Luanda'));
    $company = phaseFiveBillingCompany();
    $company['plan']->update(['interval' => $interval]);

    $this->actingAs($company['user'])
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $company['plan']->public_id])
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
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $plan->public_id])
        ->assertSessionHasNoErrors();

    $charge = SubscriptionCharge::query()->sole();
    $expectedEnd = CarbonImmutable::parse($endsAt, 'Africa/Luanda');

    expect($charge->subscription_plan_id)->toBe($plan->id)
        ->and($charge->amount_minor)->toBe(70_000_00)
        ->and($charge->period_ends_at->equalTo($expectedEnd))->toBeTrue();

    sendHostedCallback(hostedCallback(Payment::query()->sole()))->assertAccepted();
    app(FulfillHostedPayment::class)->execute(Payment::query()->sole());

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
        ->post(route('billing.checkout'), ['customer_phone' => '900000000', 'plan_public_id' => $plan->public_id])
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
    $reference = Payment::query()->sole();

    expect($plan->fresh()->amount_minor)->toBe(40_000_00)
        ->and($charge->amount_minor)->toBe(25_000_00)
        ->and($reference->amount_minor)->toBe(25_000_00);

    sendHostedCallback(hostedCallback($reference))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($reference);

    expect($charge->fresh()->status)->toBe(SubscriptionChargeStatus::Paid)
        ->and($charge->fresh()->amount_minor)->toBe(25_000_00)
        ->and(WorkspaceSubscription::query()->sole()->status)->toBe(WorkspaceSubscriptionStatus::Active);
});

test('obsolete gateway settings cannot restore Pay4All or bypass phone validation', function () {
    config(['billing.gateway' => 'pay4all', 'billing.pay4all.environment' => 'simulation']);
    $company = phaseFiveBillingCompany();
    $this->actingAs($company['user'])->withSession(phaseFivePasswordSession())
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id])
        ->assertSessionHasErrors('customer_phone');
    expect(SubscriptionCharge::count())->toBe(0);
    $this->withHeader('X-Inertia', 'true')->post(route('billing.checkout'), [
        'plan_public_id' => $company['plan']->public_id, 'customer_phone' => '900000000',
    ])->assertStatus(409)->assertHeader('X-Inertia-Location');
    expect(Payment::query()->sole()->provider)->toBe('wipay')
        ->and(EmisPaymentReference::count())->toBe(0);
});

test('unresolved real or paid legacy charges are preserved for reconciliation', function (string $environment, bool $paid) {
    $company = phaseFiveBillingCompany();
    $charge = SubscriptionCharge::factory()->create([
        'workspace_id' => $company['workspace']->id, 'subscription_plan_id' => $company['plan']->id,
        'active_checkout_key' => (string) $company['workspace']->id,
        'status' => $paid ? SubscriptionChargeStatus::Paid : SubscriptionChargeStatus::Pending,
    ]);
    $reference = EmisPaymentReference::factory()->create([
        'subscription_charge_id' => $charge->id, 'environment' => $environment,
        'status' => $paid ? EmisPaymentReferenceStatus::Paid : EmisPaymentReferenceStatus::Pending,
    ]);
    expect(fn () => startHostedTestPayment($company))->toThrow(DomainException::class)
        ->and($reference->fresh()->status)->toBe($reference->status)
        ->and($charge->fresh()->active_checkout_key)->toBe((string) $company['workspace']->id)
        ->and($reference->events()->count())->toBe(0)
        ->and(Payment::count())->toBe(0);
})->with([
    ['production', false],
    ['homologation', false],
    ['simulation', true],
]);
