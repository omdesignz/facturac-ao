<?php

use App\Actions\SignInMobileDevice;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\FcmClient;
use App\Notifications\InvoiceFellOverdue;
use App\Notifications\PushChannel;
use App\NotificationTopic;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function androidPhone(User $user): string
{
    return $user->createToken('Android', [SignInMobileDevice::TOKEN_ABILITY], now()->addDays(30))->plainTextToken;
}

function androidRegistration(array $overrides = []): array
{
    return array_replace([
        'provider' => 'fcm', 'token' => 'AbCdEfGh:'.str_repeat('Test-Token_0123', 30),
        'environment' => 'production', 'binding' => str_repeat('a', 64), 'bundle_id' => 'ao.facturac.app.flutter',
    ], $overrides);
}

function androidFirebase(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    config([
        'services.fcm.project_id' => 'facturac-test',
        'services.fcm.client_email' => 'delivery@facturac-test.iam.gserviceaccount.com',
        'services.fcm.private_key' => $pem,
        'services.apns.private_key' => null,
    ]);

    return openssl_pkey_get_details($key)['key'];
}

function androidNotice(User $user): InvoiceFellOverdue
{
    return new InvoiceFellOverdue('document-test', 'FT 2026/1', 'Cliente', 10000, 1, 'AOA', (int) $user->current_workspace_id);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config(['services.fcm.project_id' => null, 'services.apns.private_key' => null]);
});

test('Android registrations preserve long case sensitive tokens and move to the current sign in', function () {
    $first = User::factory()->withWorkspace()->create();
    $second = User::factory()->withWorkspace()->create();
    foreach ([$first, $second] as $user) {
        $this->app['auth']->forgetGuards();
        $this->withToken(androidPhone($user))->putJson(route('mobile.v1.push-device.update'), androidRegistration())->assertNoContent();
    }
    $device = PushDevice::query()->sole();
    expect($device->user_id)->toBe($second->id)
        ->and($device->token)->toBe(androidRegistration()['token'])
        ->and($device->token_hash)->toBe(hash('sha256', 'fcm:'.$device->token))
        ->and($device->toArray())->not->toHaveKeys(['token', 'token_hash', 'binding']);
});

test('different Firebase token case remains different even with a case insensitive database collation', function () {
    foreach ([androidRegistration()['token'], strtolower(androidRegistration()['token'])] as $token) {
        $user = User::factory()->withWorkspace()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken(androidPhone($user))
            ->putJson(route('mobile.v1.push-device.update'), androidRegistration(['token' => $token]))->assertNoContent();
    }
    expect(PushDevice::query()->count())->toBe(2);
});

test('Android registration rejects malformed input without saving', function (array $overrides) {
    $this->withToken(androidPhone(User::factory()->withWorkspace()->create()))
        ->putJson(route('mobile.v1.push-device.update'), androidRegistration($overrides))->assertUnprocessable();
    expect(PushDevice::query()->count())->toBe(0);
})->with([
    'missing binding' => [['binding' => null]],
    'unknown provider' => [['provider' => 'unknown']],
    'foreign bundle' => [['bundle_id' => 'com.other.app']],
    'newline token' => [['token' => "bad\n".str_repeat('a', 30)]],
    'long token' => [['token' => str_repeat('a', 4097)]],
    'array token' => [['token' => ['bad']]],
]);

test('a legacy Apple row is upgraded in place without losing its sign in', function () {
    $user = User::factory()->withWorkspace()->create();
    $plain = androidPhone($user);
    $accessId = (int) explode('|', $plain)[0];
    $id = DB::table('push_devices')->insertGetId([
        'user_id' => $user->id, 'personal_access_token_id' => $accessId,
        'token' => str_repeat('a', 64), 'environment' => 'production',
    ]);
    $this->withToken($plain)->putJson(route('mobile.v1.push-device.update'), [
        'token' => str_repeat('A', 64), 'environment' => 'sandbox', 'bundle_id' => 'ao.facturac.app.flutter',
    ])->assertNoContent();
    expect(PushDevice::query()->sole()->id)->toBe($id)
        ->and(PushDevice::query()->sole()->token_hash)->not->toBeNull();
});

test('availability is specific to the phones provider and Android alone enables the channel', function () {
    androidFirebase();
    $user = User::factory()->withWorkspace()->create();
    $this->withToken(androidPhone($user))->putJson(route('mobile.v1.push-device.update'), androidRegistration())->assertNoContent();
    $this->getJson(route('mobile.v1.notification-preferences.show', ['provider' => 'fcm']))->assertJsonPath('data.available', true);
    $this->getJson(route('mobile.v1.notification-preferences.show'))->assertJsonPath('data.available', false);
    expect($user->notificationChannels(NotificationTopic::InvoiceOverdue))->toContain(PushChannel::class);
});

test('Firebase signs OAuth requests and sends only data bound to the account', function () {
    $public = androidFirebase();
    $user = User::factory()->withWorkspace()->create();
    $device = PushDevice::factory()->for($user)->create(androidRegistration());
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/facturac-test/messages/test']),
    ]);
    app(PushChannel::class)->send($user, androidNotice($user));
    app(PushChannel::class)->send($user, androidNotice($user));
    Http::assertSent(function (Request $request) use ($public): bool {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$header, $claims, $signature] = explode('.', $request['assertion']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        expect($decoded['aud'])->toBe('https://oauth2.googleapis.com/token')
            ->and($decoded['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
            ->and($decoded['exp'] - $decoded['iat'])->toBe(3600)
            ->and(openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), $public, OPENSSL_ALGO_SHA256))->toBe(1);

        return true;
    });
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com')
        && $request->hasHeader('Authorization', 'Bearer test-access')
        && $request['message']['token'] === $device->token
        && $request['message']['data']['binding'] === $device->binding
        && ! isset($request['message']['notification'])
        && json_decode($request['message']['data']['payload'], true)['target']['public_id'] === 'document-test');
    Http::assertSentCount(3);
});

test('only an explicit Firebase unregistered result removes a device', function (int $status, array $body, bool $deleted) {
    androidFirebase();
    $user = User::factory()->withWorkspace()->create();
    $device = PushDevice::factory()->for($user)->create(androidRegistration());
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response($body, $status),
    ]);
    app(PushChannel::class)->send($user, androidNotice($user));
    expect($device->fresh() === null)->toBe($deleted);
})->with([
    'unregistered' => [404, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], true],
    'invalid payload' => [400, ['error' => ['status' => 'INVALID_ARGUMENT']], false],
    'wrong project' => [403, ['error' => ['status' => 'SENDER_ID_MISMATCH']], false],
    'missing project' => [404, ['error' => ['status' => 'NOT_FOUND']], false],
    'unavailable' => [503, [], false],
]);

test('unconfigured Firebase does not make requests or remove registrations', function () {
    $user = User::factory()->withWorkspace()->create();
    $device = PushDevice::factory()->for($user)->create(androidRegistration());
    app(PushChannel::class)->send($user, androidNotice($user));
    Http::assertNothingSent();
    expect(FcmClient::isConfigured())->toBeFalse()->and($device->fresh())->not->toBeNull();
});

test('a refreshed Firebase token differing only in case replaces the same sign in registration', function () {
    $user = User::factory()->withWorkspace()->create();
    $plain = androidPhone($user);
    foreach ([androidRegistration()['token'], strtolower(androidRegistration()['token'])] as $token) {
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->putJson(route('mobile.v1.push-device.update'), androidRegistration(['token' => $token]))->assertNoContent();
    }
    expect(PushDevice::query()->sole()->token)->toBe(strtolower(androidRegistration()['token']));
});

test('Firebase authorization failures are contained and a new attempt requests fresh authorization', function () {
    androidFirebase();
    $user = User::factory()->withWorkspace()->create();
    $device = PushDevice::factory()->for($user)->create(androidRegistration());
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::sequence()->push([], 401)->push(['name' => 'delivered']),
    ]);
    app(PushChannel::class)->send($user, androidNotice($user));
    app(PushChannel::class)->send($user, androidNotice($user));
    Http::assertSentCount(4);
    expect($device->fresh())->not->toBeNull();
});

test('broken Apple credentials do not prevent Android delivery to the same user', function () {
    androidFirebase();
    config(['services.apns.key_id' => 'invalid', 'services.apns.team_id' => 'invalid', 'services.apns.private_key' => '-----BEGIN INVALID']);
    $user = User::factory()->withWorkspace()->create();
    PushDevice::factory()->for($user)->create();
    $android = PushDevice::factory()->for($user)->create(androidRegistration());
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'delivered']),
    ]);
    app(PushChannel::class)->send($user, androidNotice($user));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com') && $request['message']['token'] === $android->token);
});
