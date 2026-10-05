<?php

use App\Actions\AcceptWiPayCallback;
use App\Actions\FulfillHostedPayment;
use App\Actions\StartWiPaySubscriptionCheckout;
use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Data\GatewayAccessTokenData;
use App\Billing\Exceptions\PaymentGatewayException;
use App\Jobs\FulfillPayment;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Notifications\SubscriptionActivated;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use App\SubscriptionInterval;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    config()->set(['billing.gateway' => 'wipay', 'billing.wipay.environment' => 'sandbox',
        'billing.wipay.client_id' => 'wp_hosted_test', 'billing.wipay.client_secret' => 'WPS_hosted_test',
        'billing.wipay.production_enabled' => false, 'billing.wipay.review_after_minutes' => 1440,
        'billing.wipay.callback_url' => 'https://merchant.example/webhooks/wipay']);
    URL::forceRootUrl('https://merchant.example');
    URL::forceScheme('https');
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

/** @return array{user: User, workspace: Workspace, plan: SubscriptionPlan} */
function hostedBillingCompany(): array
{
    $user = User::factory()->withWorkspace('Hosted billing')->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('hosted-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('["hosted-recovery"]'),
        'two_factor_confirmed_at' => now(),
    ]);
    $workspace = $user->currentWorkspace()->firstOrFail();
    $plan = SubscriptionPlan::factory()->create(['amount_minor' => 2_500_000, 'currency_code' => 'AOA']);

    return compact('user', 'workspace', 'plan');
}

function fakeHostedProvider(?callable $paymentResponse = null): void
{
    Http::fake([
        'api.wipay.ao/v1/credentials/token' => fn (Request $request) => Http::response([
            'access_token' => 'hosted-'.$request['scope'].'-token', 'token_type' => 'Bearer',
            'scope' => $request['scope'], 'expires_in' => $request['scope'] === 'payment' ? 3600 : 86400,
        ]),
        'api.wipay.ao/v1/hosts/payments' => $paymentResponse ?? fn () => Http::response('', 303, [
            'Location' => 'https://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
        ]),
    ]);
}

/** @param array<string, mixed> $overrides */
function hostedCallback(Payment $payment, array $overrides = []): string
{
    return json_encode(array_replace([
        'id' => $payment->provider_payment_id ?? 'f3c6ff4c-3f05-4ce6-a253-28b871d682ab',
        'amount' => intdiv($payment->amount_minor, 100).'.'.str_pad((string) ($payment->amount_minor % 100), 2, '0'),
        'currency' => 'aoa', 'status' => 'accepted', 'status_reason' => '2000',
        'status_datetime' => now()->toIso8601String(), 'customer' => '900000000',
        'reference_id' => $payment->public_id, 'processor' => 'gpo',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $company */
function startHostedTestPayment(array $company): Payment
{
    return app(StartWiPaySubscriptionCheckout::class)->execute($company['workspace'], $company['plan'], $company['user'], '900000000');
}

function sendHostedCallback(string $body, ?string $signature = null): TestResponse
{
    return test()->call('POST', route('webhooks.wipay'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'hosted-signature-token'),
    ], $body);
}

test('checkout reuses a durable attempt and produces an Inertia external redirect', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    foreach (range(1, 2) as $attempt) {
        $this->actingAs($company['user'])->withSession(['auth.password_confirmed_at' => time()])
            ->withHeader('X-Inertia', 'true')->post(route('billing.checkout'), [
                'plan_public_id' => $company['plan']->public_id, 'customer_phone' => '900000000',
            ])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64));
    }
    expect(Payment::query()->count())->toBe(1)->and(SubscriptionCharge::query()->count())->toBe(1);
    Http::assertSentCount(3);
    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->getRawOriginal('signature_token'))->not->toContain('hosted-signature-token')
        ->and($payment->getRawOriginal('customer_identifier'))->not->toContain('900000000')
        ->and($payment->getRawOriginal('checkout_url'))->not->toContain('nonce=')
        ->and($payment->toArray())->not->toHaveKeys(['checkout_url', 'signature_token', 'customer_identifier']);
    expect(WorkspaceSubscription::query()->count())->toBe(0);
});

test('a signed callback activates once and reordered duplicate bodies cannot extend the subscription', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    $body = hostedCallback($payment);
    sendHostedCallback($body)->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);
    $endsAt = WorkspaceSubscription::query()->sole()->current_period_ends_at;
    $reordered = json_encode(array_reverse(json_decode($body, true), true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    sendHostedCallback($reordered)->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->fulfilled_at)->not->toBeNull()
        ->and(WorkspaceSubscription::query()->count())->toBe(1)
        ->and(WorkspaceSubscription::query()->sole()->current_period_ends_at)->toEqual($endsAt)
        ->and(SubscriptionCharge::query()->sole()->active_checkout_key)->toBeNull()
        ->and(PaymentEvent::query()->where('event_type', 'callback-accepted')->count())->toBe(1);
    Notification::assertSentToTimes($company['user'], SubscriptionActivated::class, 1);
});

test('a rejected callback releases checkout without granting a subscription', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    sendHostedCallback(hostedCallback($payment, ['status' => 'rejected', 'status_reason' => '3002']))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Rejected)
        ->and(SubscriptionCharge::query()->sole()->status)->toBe(SubscriptionChargeStatus::Failed)
        ->and(SubscriptionCharge::query()->sole()->active_checkout_key)->toBeNull()
        ->and(WorkspaceSubscription::query()->count())->toBe(0);
});

test('unsigned and altered bodies cannot change payment state', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    $body = hostedCallback($payment);
    sendHostedCallback($body, '')->assertUnauthorized();
    sendHostedCallback($body."\n", hash_hmac('sha256', $body, 'hosted-signature-token'))->assertUnauthorized();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    Queue::assertNothingPushed();
});

test('amount or provider identity mismatches require review and cannot grant access', function (array $override) {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    sendHostedCallback(hostedCallback($payment, $override))->assertUnprocessable();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Review)->and(WorkspaceSubscription::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'amount' => [['amount' => '24999.99']],
    'provider id' => [['id' => 'a3c6ff4c-3f05-4ce6-a253-28b871d682ab']],
]);

test('a timeout retains the original attempt even after its local deadline', function () {
    fakeHostedProvider(Http::failedConnection());
    $company = hostedBillingCompany();
    expect(fn () => startHostedTestPayment($company))->toThrow(PaymentGatewayException::class);
    $this->travel(2)->days();
    expect(fn () => startHostedTestPayment($company))->toThrow(PaymentGatewayException::class);
    Http::assertSentCount(3);
    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Review)
        ->and(SubscriptionCharge::query()->sole()->active_checkout_key)->toBe((string) $company['workspace']->id);
    $payment = Payment::query()->sole();
    sendHostedCallback(hostedCallback($payment))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);
    expect(WorkspaceSubscription::query()->count())->toBe(1);
});

test('a callback racing the creation response is durably accepted without a state regression', function () {
    fakeHostedProvider(function (Request $request) {
        $payment = Payment::query()->where('public_id', $request['reference_id'])->sole();
        $body = hostedCallback($payment);
        app(AcceptWiPayCallback::class)->execute($body, hash_hmac('sha256', $body, 'hosted-signature-token'));

        return Http::response('', 303, ['Location' => 'https://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64)]);
    });
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    expect($payment->status)->toBe(PaymentStatus::Paid)->and($payment->provider_payment_id)->not->toBeNull();
    app(FulfillHostedPayment::class)->execute($payment);
    expect(WorkspaceSubscription::query()->count())->toBe(1);
});

test('scheduler recovery requeues unfulfilled payments without calling the provider', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->artisan('payments:recover')->assertSuccessful();
    Queue::assertPushed(FulfillPayment::class, fn (FulfillPayment $job): bool => $job->paymentId === $payment->id);
    Http::assertNothingSent();
    app(FulfillHostedPayment::class)->execute($payment);
    expect($payment->fresh()->fulfilled_at)->not->toBeNull();
});

test('unresolved requests are flagged for review without being expired or charged again', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Creating]);
    $payment->forceFill(['request_started_at' => now()->subMinutes(3)])->save();
    $charge = $payment->payable;
    $charge->forceFill(['active_checkout_key' => (string) $payment->workspace_id])->save();
    $this->artisan('payments:recover')->assertSuccessful();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Review)
        ->and($charge->fresh()->active_checkout_key)->not->toBeNull();
    Http::assertNothingSent();
});

test('a browser success return never counts as payment confirmation', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    $response = $this->actingAs($company['user'])->get(route('billing.show', ['status' => 'accepted', 'paid' => true]));
    $response->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->where('activePayment.public_id', $payment->public_id)->where('subscription', null)
        ->missing('activePayment.checkout_url')->missing('activePayment.signature_token')->missing('activePayment.customer_identifier'));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('another workspace cannot resume a hosted payment', function () {
    fakeHostedProvider();
    $owner = hostedBillingCompany();
    $payment = startHostedTestPayment($owner);
    $other = hostedBillingCompany();
    $this->actingAs($other['user'])->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.payments.resume', $payment))->assertNotFound();
});

test('payment mutation still requires MFA and a valid customer phone', function () {
    $company = hostedBillingCompany();
    $this->actingAs($company['user'])->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id, 'customer_phone' => '+244900000000'])
        ->assertSessionHasErrors('customer_phone');
    $company['user']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $this->post(route('billing.checkout'), ['plan_public_id' => $company['plan']->public_id, 'customer_phone' => '900000000'])
        ->assertRedirect(route('settings.security'));
    Http::assertNothingSent();
});

test('sandbox callbacks cannot grant a real production subscription', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    app()->instance('env', 'production');
    app(FulfillHostedPayment::class)->execute($payment);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Review)->and(WorkspaceSubscription::query()->count())->toBe(0);
    app()->instance('env', 'testing');
});

test('conflicting terminal callbacks never downgrade a paid payment', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    sendHostedCallback(hostedCallback($payment))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);
    sendHostedCallback(hostedCallback($payment, ['status' => 'rejected', 'status_reason' => '3002']))->assertAccepted();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->failure_code)->toBe('callback_conflict')
        ->and(PaymentEvent::query()->where('event_type', 'callback-conflict')->count())->toBe(1);
});

test('rate limited creation resumes the same uncharged attempt only after the documented delay', function () {
    fakeHostedProvider(Http::sequence()->push('', 429, ['Retry-After' => '10'])
        ->push('', 303, ['Location' => 'https://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64)]));
    $company = hostedBillingCompany();
    expect(fn () => startHostedTestPayment($company))->toThrow(PaymentGatewayException::class);
    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Created)->and($payment->request_started_at)->toBeNull();
    expect(fn () => startHostedTestPayment($company))->toThrow(PaymentGatewayException::class);
    Http::assertSentCount(3);
    $this->travel(11)->seconds();
    $resumed = startHostedTestPayment($company);
    expect($resumed->id)->toBe($payment->id)->and($resumed->status)->toBe(PaymentStatus::Pending)
        ->and(Payment::query()->count())->toBe(1)->and(SubscriptionCharge::query()->count())->toBe(1);
    Http::assertSentCount(4);
});

test('a signed acceptance received before an HTTP failure remains authoritative', function () {
    fakeHostedProvider(function (Request $request) {
        $payment = Payment::query()->where('public_id', $request['reference_id'])->sole();
        $body = hostedCallback($payment);
        app(AcceptWiPayCallback::class)->execute($body, hash_hmac('sha256', $body, 'hosted-signature-token'));

        return Http::response('', 503);
    });
    $payment = startHostedTestPayment(hostedBillingCompany());
    expect($payment->status)->toBe(PaymentStatus::Paid);
    app(FulfillHostedPayment::class)->execute($payment);
    expect(WorkspaceSubscription::query()->count())->toBe(1);
});

test('renewal preserves already paid time and honors the purchased interval after a plan edit', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $company['plan']->update(['interval' => SubscriptionInterval::Monthly]);
    $oldStart = now()->subDays(10)->startOfSecond();
    $oldEnd = now()->addDays(20)->startOfSecond();
    WorkspaceSubscription::factory()->create([
        'workspace_id' => $company['workspace']->id, 'subscription_plan_id' => $company['plan']->id,
        'status' => WorkspaceSubscriptionStatus::Active, 'current_period_started_at' => $oldStart, 'current_period_ends_at' => $oldEnd,
    ]);
    $payment = startHostedTestPayment($company);
    $company['plan']->update(['interval' => SubscriptionInterval::Annual, 'amount_minor' => 99_000_000]);
    sendHostedCallback(hostedCallback($payment))->assertAccepted();
    app(FulfillHostedPayment::class)->execute($payment);
    expect(WorkspaceSubscription::query()->sole()->current_period_started_at)->toEqual($oldStart)
        ->and(WorkspaceSubscription::query()->sole()->current_period_ends_at)->toEqual($oldEnd->toImmutable()->addMonthNoOverflow())
        ->and(SubscriptionCharge::query()->sole()->amount_minor)->toBe(2_500_000);
});

test('payment amounts and evidence cannot be changed after recording', function () {
    $payment = Payment::factory()->create();
    expect(fn () => $payment->forceFill(['amount_minor' => $payment->amount_minor + 1])->save())->toThrow(DomainException::class);
    $event = PaymentEvent::factory()->create(['payment_id' => $payment->id]);
    expect(fn () => $event->update(['event_type' => 'tampered']))->toThrow(DomainException::class);
    expect(fn () => $event->delete())->toThrow(DomainException::class);
});

test('disabled payment environments cannot be resumed through a previously saved checkout URL', function () {
    fakeHostedProvider();
    $company = hostedBillingCompany();
    $payment = startHostedTestPayment($company);
    config()->set('billing.gateway', 'pay4all');
    $this->actingAs($company['user'])->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('billing.show'))->post(route('billing.payments.resume', $payment))
        ->assertRedirect(route('billing.show'))->assertSessionHas('error');
    $this->get(route('billing.show'))->assertInertia(fn (Assert $page) => $page->where('activePayment.can_resume', false));
    Http::assertSentCount(3);
});

test('callback verification tolerates signature key rotation without trusting another client', function () {
    fakeHostedProvider();
    $payment = startHostedTestPayment(hostedBillingCompany());
    app(WiPayTokenStore::class)->remember($payment->client_fingerprint,
        new GatewayAccessTokenData('new-signature-token', 'signature', CarbonImmutable::now()->addDay()));
    $body = hostedCallback($payment);
    sendHostedCallback($body, hash_hmac('sha256', $body, 'different-merchant-token'))->assertUnauthorized();
    config()->set('billing.wipay.client_secret', 'WPS_rotated_secret');
    sendHostedCallback($body, hash_hmac('sha256', $body, 'new-signature-token'))->assertAccepted();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

test('fulfillment rejects a payable belonging to another workspace', function () {
    $payment = Payment::factory()->create(['workspace_id' => Workspace::factory(), 'status' => PaymentStatus::Paid]);
    expect(fn () => app(FulfillHostedPayment::class)->execute($payment))->toThrow(DomainException::class);
    expect($payment->fresh()->fulfilled_at)->toBeNull()->and(WorkspaceSubscription::query()->count())->toBe(0);
});

test('callback timestamps preserve their instant when the provider uses a different timezone', function () {
    fakeHostedProvider();
    $payment = startHostedTestPayment(hostedBillingCompany());
    $occurredAt = CarbonImmutable::now('UTC')->startOfSecond();
    sendHostedCallback(hostedCallback($payment, ['status_datetime' => $occurredAt->toIso8601String()]))->assertAccepted();
    expect($payment->fresh()->paid_at->getTimestamp())->toBe($occurredAt->getTimestamp())
        ->and($payment->events()->where('event_type', 'callback-accepted')->sole()->occurred_at->getTimestamp())->toBe($occurredAt->getTimestamp());
});
