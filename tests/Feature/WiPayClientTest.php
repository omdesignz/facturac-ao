<?php

use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Data\CreateHostedPaymentData;
use App\Billing\Data\PaymentCallbackData;
use App\Billing\Exceptions\PaymentGatewayException;
use App\Billing\WiPay\WiPayClient;
use App\Billing\WiPay\WiPayConfiguration;
use App\Billing\WiPay\WiPaySignatureVerifier;
use App\Models\WiPayToken;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

beforeEach(function (): void {
    config()->set([
        'billing.wipay.client_id' => 'wp_contract_test',
        'billing.wipay.client_secret' => 'WPS_contract_test',
        'billing.wipay.environment' => 'sandbox',
        'billing.wipay.production_enabled' => false,
    ]);
    Http::preventStrayRequests();
});

function wiPayContractRequest(): CreateHostedPaymentData
{
    return new CreateHostedPaymentData('order-123', 12_345, 'AOA', '900000000',
        'https://merchant.example/webhooks/wipay', 'https://merchant.example/success', 'https://merchant.example/failure');
}

function wiPayContractToken(Request $request): PromiseInterface
{
    return Http::response(['access_token' => 'test-'.$request['scope'].'-token', 'token_type' => 'Bearer',
        'scope' => $request['scope'], 'expires_in' => $request['scope'] === 'payment' ? 3600 : 86400]);
}

test('WiPay uses the documented OAuth scopes and handles genuine checkout redirects without following them', function (string $host) {
    $id = 'f3c6ff4c-3f05-4ce6-a253-28b871d682ab';
    $location = 'https://'.$host.'/?id='.$id.'&nonce='.str_repeat('a', 64);
    Http::fake([
        'api.wipay.ao/v1/credentials/token' => fn (Request $request) => wiPayContractToken($request),
        'api.wipay.ao/v1/hosts/payments' => Http::response('', 303, ['Location' => $location]),
    ]);
    $client = app(WiPayClient::class);
    expect($client->signatureToken())->toBe('test-signature-token');
    $result = $client->createPayment(wiPayContractRequest());
    $client->createPayment(wiPayContractRequest());

    expect($result->providerId)->toBe($id)->and($result->checkoutUrl)->toBe($location);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.wipay.ao/v1/hosts/payments'
        && $request->hasHeader('Authorization', 'Bearer test-payment-token')
        && $request['amount'] === '123.45' && $request['currency'] === 'aoa'
        && $request['reference_id'] === 'order-123' && $request['customer'] === '900000000');
    Http::assertSentCount(4);
    expect(WiPayToken::query()->count())->toBe(2);
    $stored = WiPayToken::query()->where('scope', 'signature')->sole();
    expect($stored->getRawOriginal('access_token'))->not->toContain('test-signature-token')
        ->and($stored->toArray())->not->toHaveKey('access_token');
})->with([
    'documented checkout host' => 'hosted.wipay.ao',
    'current sandbox checkout host' => 'pay.wiza.ao',
]);

test('expired credentials refresh while old signature keys remain available for callbacks', function () {
    Http::fake(['api.wipay.ao/v1/credentials/token' => fn (Request $request) => wiPayContractToken($request)]);
    $client = app(WiPayClient::class);
    $client->checkConnection();
    WiPayToken::query()->where('scope', 'payment')->update(['expires_at' => now()->subSecond()]);
    $client->checkConnection();
    Http::assertSentCount(3);
    $client->checkConnection();
    Http::assertSentCount(3);
    expect(app(WiPayTokenStore::class)->signatureKeys(app(WiPayConfiguration::class)->fingerprint()))
        ->toContain('test-signature-token');
});

test('lost responses and server failures are never retried automatically', function ($response) {
    Http::fake([
        'api.wipay.ao/v1/credentials/token' => fn (Request $request) => wiPayContractToken($request),
        'api.wipay.ao/v1/hosts/payments' => $response,
    ]);
    try {
        app(WiPayClient::class)->createPayment(wiPayContractRequest());
        Assert::fail('An ambiguous provider response must not be accepted.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('provider-secret');
    }
    Http::assertSentCount(2);
})->with([
    'connection lost' => fn () => Http::failedConnection(),
    'server error' => fn () => Http::response(['debug' => 'provider-secret'], 503),
    'unexpected success body' => fn () => Http::response(['paid' => true], 200),
]);

test('a documented rate limit is a definite rejection with a bounded retry delay', function () {
    Http::fake([
        'api.wipay.ao/v1/credentials/token' => fn (Request $request) => wiPayContractToken($request),
        'api.wipay.ao/v1/hosts/payments' => Http::response('', 429, ['Retry-After' => '999']),
    ]);
    try {
        app(WiPayClient::class)->createPayment(wiPayContractRequest());
        Assert::fail('Expected a rate limit.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->outcomeUnknown)->toBeFalse()->and($exception->retryAfterSeconds)->toBe(60);
    }
    Http::assertSentCount(2);
});

test('only the genuine HTTPS hosted gateway can receive a checkout redirect', function (string $url) {
    expect(fn () => app(WiPayClient::class)->checkoutId($url))->toThrow(PaymentGatewayException::class);
})->with([
    'wrong host' => 'https://evil.example/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'userinfo' => 'https://attacker@hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'wrong scheme' => 'http://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'wrong port' => 'https://hosted.wipay.ao:8443/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'missing nonce' => 'https://hosted.wipay.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab',
    'duplicate array id' => 'https://hosted.wipay.ao/?id[]=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'lookalike current host' => 'https://pay.wiza.ao.evil.example/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'userinfo on current host' => 'https://attacker@pay.wiza.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'wrong scheme on current host' => 'http://pay.wiza.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'wrong port on current host' => 'https://pay.wiza.ao:8443/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'unexpected path on current host' => 'https://pay.wiza.ao/elsewhere?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64),
    'fragment on current host' => 'https://pay.wiza.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce='.str_repeat('a', 64).'#unsafe',
    'invalid nonce on current host' => 'https://pay.wiza.ao/?id=f3c6ff4c-3f05-4ce6-a253-28b871d682ab&nonce=invalid',
]);

test('production is explicitly gated and missing credentials never fall back to simulation', function () {
    config()->set('billing.wipay.environment', 'production');
    expect(fn () => app(WiPayClient::class)->checkConnection())->toThrow(PaymentGatewayException::class);
    config()->set(['billing.wipay.environment' => 'sandbox', 'billing.wipay.client_secret' => '']);
    expect(fn () => app(WiPayClient::class)->checkConnection())->toThrow(PaymentGatewayException::class);
    config()->set(['billing.wipay.environment' => 'invalid', 'billing.wipay.client_secret' => 'WPS_contract_test']);
    expect(fn () => app(WiPayClient::class)->checkConnection())->toThrow(PaymentGatewayException::class);
    Http::assertNothingSent();
});

test('callback signatures are hexadecimal HMAC SHA256 of the exact raw body', function () {
    $body = '{"amount":"123.45", "status":"accepted"}';
    $signature = hash_hmac('sha256', $body, 'signature-access-token');
    $verify = new WiPaySignatureVerifier;
    expect($verify->verify($body, $signature, 'signature-access-token'))->toBeTrue()
        ->and($verify->verify(str_replace(' ', '', $body), $signature, 'signature-access-token'))->toBeFalse()
        ->and($verify->verify($body, $signature, 'different-key'))->toBeFalse()
        ->and($verify->verify($body, 'sha256='.$signature, 'signature-access-token'))->toBeFalse();
});

test('an invalidated OAuth payment token is renewed on the next attempt without retrying a payment POST', function () {
    Http::fake([
        'api.wipay.ao/v1/credentials/token' => fn (Request $request) => wiPayContractToken($request),
        'api.wipay.ao/v1/hosts/payments' => Http::response('', 401),
    ]);
    $client = app(WiPayClient::class);
    expect(fn () => $client->createPayment(wiPayContractRequest()))->toThrow(PaymentGatewayException::class);
    Http::assertSentCount(2);
    expect(fn () => $client->createPayment(wiPayContractRequest()))->toThrow(PaymentGatewayException::class);
    Http::assertSentCount(4);
});

test('invalid callback dates, amount types, and status combinations are rejected', function (array $override) {
    $body = json_encode(array_replace([
        'id' => 'f3c6ff4c-3f05-4ce6-a253-28b871d682ab', 'reference_id' => 'order-123',
        'amount' => '123.45', 'currency' => 'aoa', 'status' => 'accepted', 'status_reason' => '2000',
        'status_datetime' => now()->toIso8601String(), 'processor' => 'gpo',
    ], $override), JSON_THROW_ON_ERROR);
    expect(fn () => PaymentCallbackData::fromRawBody($body))->toThrow(InvalidArgumentException::class);
})->with([
    'invalid calendar date' => [['status_datetime' => '2026-02-30T12:00:00Z']],
    'invalid clock time' => [['status_datetime' => '2026-02-28T25:00:00Z']],
    'numeric amount' => [['amount' => 123.45]],
    'negative amount' => [['amount' => '-123.45']],
    'extra decimals' => [['amount' => '123.456']],
    'zero amount' => [['amount' => '0.00']],
    'wrong currency' => [['currency' => 'usd']],
    'accepted with rejection reason' => [['status_reason' => '3000']],
    'rejected with acceptance reason' => [['status' => 'rejected']],
    'untrusted future date' => [['status_datetime' => '9999-12-31T23:59:59Z']],
]);

test('monetary payloads use integers and exact decimal strings without floating point', function () {
    expect((new CreateHostedPaymentData('cent', 1, 'AOA', '900000000', 'https://merchant.example/callback'))->payload()['amount'])
        ->toBe('0.01');
    $body = json_encode(['id' => 'f3c6ff4c-3f05-4ce6-a253-28b871d682ab', 'reference_id' => 'cent',
        'amount' => '0.01', 'currency' => 'aoa', 'status' => 'accepted', 'status_reason' => '2000',
        'status_datetime' => now()->toIso8601String(), 'processor' => 'gpo'], JSON_THROW_ON_ERROR);
    expect(PaymentCallbackData::fromRawBody($body)->amountMinor)->toBe(1);
});
