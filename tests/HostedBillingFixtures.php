<?php

use App\Actions\StartWiPaySubscriptionCheckout;
use App\Models\Payment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

function configureHostedBilling(): void
{
    config()->set(['billing.wipay.environment' => 'sandbox',
        'billing.wipay.client_id' => 'wp_hosted_test', 'billing.wipay.client_secret' => 'WPS_hosted_test',
        'billing.wipay.production_enabled' => false, 'billing.wipay.review_after_minutes' => 1440,
        'billing.wipay.callback_url' => 'https://merchant.example/webhooks/wipay']);
    URL::forceRootUrl('https://merchant.example');
    URL::forceScheme('https');
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
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
