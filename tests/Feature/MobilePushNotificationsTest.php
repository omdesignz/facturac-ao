<?php

use App\Actions\SignInMobileDevice;
use App\Models\PushDevice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\CustomerOverCreditLimit;
use App\Notifications\DocumentRejectedByAgt;
use App\Notifications\InvoiceFellOverdue;
use App\Notifications\PushChannel;
use App\NotificationTopic;
use App\WorkspaceRole;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Points the APNs client at a key made for the test and returns its public
 * half, so a test can check the signature Apple would check.
 */
function configureApns(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);

    config([
        'services.apns.key_id' => 'KEY1234567',
        'services.apns.team_id' => 'TEAM123456',
        'services.apns.private_key' => str_replace("\n", '\n', $privatePem),
        'services.apns.bundle_id' => 'ao.facturac.app',
    ]);

    return openssl_pkey_get_details($key)['key'];
}

/** A phone signed in for the user, with its plain-text token for requests. */
function signedInPhone(User $user): array
{
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY], now()->addDays(30));

    return ['plain' => $token->plainTextToken, 'id' => $token->accessToken->getKey()];
}

function overdueNotice(User $user): InvoiceFellOverdue
{
    return new InvoiceFellOverdue(
        documentPublicId: '01JBQ5Z7Y2V9T3K8M4N6P0R1S2',
        documentNo: 'FT 2026/41',
        customerName: 'Padaria Cazenga',
        outstandingMinor: 12_500_000,
        daysPastDue: 3,
        currencyCode: 'AOA',
        workspace: (int) $user->current_workspace_id,
    );
}

/** Turns a JSON web token signature back into the DER that OpenSSL verifies. */
function joseToDer(string $raw): string
{
    $integer = function (string $half): string {
        $half = ltrim($half, "\x00");

        if (ord($half[0]) > 0x7F) {
            $half = "\x00".$half;
        }

        return "\x02".chr(strlen($half)).$half;
    };

    $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

    return "\x30".chr(strlen($sequence)).$sequence;
}

function base64UrlDecode(string $value): string
{
    return base64_decode(strtr($value, '-_', '+/'));
}

// ------------------------------------------------------------------ the phone

test('a phone gives its token for the sign-in it comes from', function () {
    $user = User::factory()->withWorkspace()->create();
    $phone = signedInPhone($user);
    $token = str_repeat('ab', 32);

    $this->withToken($phone['plain'])
        ->putJson(route('mobile.v1.push-device.update'), ['token' => strtoupper($token), 'environment' => 'sandbox'])
        ->assertNoContent();

    expect(PushDevice::query()->sole())
        ->user_id->toBe($user->id)
        ->personal_access_token_id->toBe($phone['id'])
        ->token->toBe($token)
        ->environment->toBe('sandbox');
});

test('a new token from the same sign-in replaces the old one', function () {
    $user = User::factory()->withWorkspace()->create();
    $phone = signedInPhone($user);

    foreach (['aa', 'bb', 'bb'] as $byte) {
        $this->withToken($phone['plain'])
            ->putJson(route('mobile.v1.push-device.update'), ['token' => str_repeat($byte, 32), 'environment' => 'production'])
            ->assertNoContent();
        $this->app['auth']->forgetGuards();
    }

    expect(PushDevice::query()->pluck('token')->all())->toBe([str_repeat('bb', 32)]);
});

test('a phone someone else signs in to stops telling the last person', function () {
    $before = User::factory()->withWorkspace()->create();
    $after = User::factory()->withWorkspace()->create();
    $token = str_repeat('cd', 32);

    $this->withToken(signedInPhone($before)['plain'])
        ->putJson(route('mobile.v1.push-device.update'), ['token' => $token, 'environment' => 'production']);
    $this->app['auth']->forgetGuards();

    $this->withToken(signedInPhone($after)['plain'])
        ->putJson(route('mobile.v1.push-device.update'), ['token' => $token, 'environment' => 'production'])
        ->assertNoContent();

    expect(PushDevice::query()->sole()->user_id)->toBe($after->id);
});

test('signing out of the phone forgets where to reach it', function () {
    $user = User::factory()->withWorkspace()->create();
    $phone = signedInPhone($user);
    PushDevice::factory()->for($user)->create(['personal_access_token_id' => $phone['id']]);

    $this->withToken($phone['plain'])->deleteJson(route('mobile.v1.sessions.destroy-current'))->assertNoContent();

    expect(PushDevice::query()->count())->toBe(0);
});

test('a token that is not Apple\'s shape is refused', function (array $payload) {
    $user = User::factory()->withWorkspace()->create();

    $this->withToken(signedInPhone($user)['plain'])
        ->putJson(route('mobile.v1.push-device.update'), $payload)
        ->assertUnprocessable();

    expect(PushDevice::query()->count())->toBe(0);
})->with([
    'not hex' => [['token' => str_repeat('zz', 32), 'environment' => 'production']],
    'too short' => [['token' => 'abcd', 'environment' => 'production']],
    'unknown environment' => [['token' => str_repeat('ab', 32), 'environment' => 'staging']],
]);

// -------------------------------------------------------------- the choices

test('the phone lists each topic with whether it buzzes', function () {
    $user = User::factory()->withWorkspace()->create();

    $response = $this->withToken(signedInPhone($user)['plain'])
        ->getJson(route('mobile.v1.notification-preferences.show'))
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonCount(count(NotificationTopic::configurable()), 'data.topics');

    $topics = collect($response->json('data.topics'))->keyBy('key');

    expect($topics['invoice_overdue'])->toMatchArray(['label' => 'Facturas vencidas', 'push' => true])
        ->and($topics['document_accepted']['push'])->toBeFalse()
        ->and($topics->has('support_access'))->toBeFalse();
});

test('the phone changes only the topics it sends, and only for itself', function () {
    configureApns();
    $user = User::factory()->withWorkspace()->create([
        'notification_preferences' => ['invoice_overdue' => ['database' => true, 'mail' => false]],
    ]);

    $this->withToken(signedInPhone($user)['plain'])
        ->putJson(route('mobile.v1.notification-preferences.update'), ['topics' => ['invoice_overdue' => false, 'document_accepted' => true]])
        ->assertOk()
        ->assertJsonPath('data.available', true);

    $user->refresh();

    expect($user->notification_preferences['invoice_overdue'])->toBe(['database' => true, 'mail' => false, 'push' => false])
        ->and($user->wantsNotification(NotificationTopic::DocumentAccepted, 'push'))->toBeTrue()
        ->and($user->wantsNotification(NotificationTopic::DocumentRejected, 'push'))->toBeTrue();
});

test('the phone cannot silence what may not be silenced', function (string $topic) {
    $user = User::factory()->withWorkspace()->create();

    $this->withToken(signedInPhone($user)['plain'])
        ->putJson(route('mobile.v1.notification-preferences.update'), ['topics' => [$topic => false]])
        ->assertUnprocessable();

    expect($user->refresh()->notification_preferences)->toBeNull();
})->with(['support_access', 'nonsense']);

test('saving the web settings keeps what was chosen on the phone', function () {
    $user = User::factory()->withWorkspace()->create([
        'notification_preferences' => ['invoice_overdue' => ['database' => true, 'mail' => true, 'push' => false]],
    ]);

    $topics = collect(NotificationTopic::configurable())
        ->mapWithKeys(fn (NotificationTopic $topic): array => [$topic->value => ['database' => true, 'mail' => false]])
        ->all();

    $this->actingAs($user)->put(route('settings.notifications.update'), ['topics' => $topics])->assertRedirect();

    expect($user->refresh()->notification_preferences['invoice_overdue'])->toBe(['database' => true, 'mail' => false, 'push' => false]);
});

// ------------------------------------------------------------- the channel

test('push joins the channels only for someone with a signed-in phone', function () {
    configureApns();
    $user = User::factory()->withWorkspace()->create();

    expect($user->notificationChannels(NotificationTopic::InvoiceOverdue))->not->toContain(PushChannel::class);

    PushDevice::factory()->for($user)->create();

    expect($user->notificationChannels(NotificationTopic::InvoiceOverdue))->toContain(PushChannel::class)
        ->and($user->notificationChannels(NotificationTopic::DocumentAccepted))->not->toContain(PushChannel::class);
});

test('a phone whose sign-in expired, or a server without Apple\'s key, gets nothing', function () {
    configureApns();
    $user = User::factory()->withWorkspace()->create();
    $device = PushDevice::factory()->for($user)->create();

    PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->update(['expires_at' => now()->subMinute()]);
    expect($user->notificationChannels(NotificationTopic::InvoiceOverdue))->not->toContain(PushChannel::class);

    PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->update(['expires_at' => now()->addDay()]);
    config(['services.apns.private_key' => null]);
    expect($user->notificationChannels(NotificationTopic::InvoiceOverdue))->not->toContain(PushChannel::class);
});

test('a notice reaches Apple signed, addressed and ready to open the document', function () {
    $publicKey = configureApns();
    Http::fake(['*' => Http::response(status: 200)]);

    $user = User::factory()->withWorkspace('Padaria Central')->create();
    $device = PushDevice::factory()->for($user)->create(['environment' => 'sandbox']);
    $workspace = Workspace::query()->findOrFail($user->current_workspace_id);

    Notification::sendNow($user, overdueNotice($user), [PushChannel::class]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($device, $publicKey, $workspace): bool {
        [$header, $claims, $signature] = explode('.', str_replace('Bearer ', '', $request->header('Authorization')[0]));

        expect($request->url())->toBe("https://api.sandbox.push.apple.com/3/device/{$device->token}")
            ->and($request->header('apns-topic')[0])->toBe('ao.facturac.app')
            ->and($request->header('apns-push-type')[0])->toBe('alert')
            ->and($request->header('apns-collapse-id')[0])->toBe('invoice_overdue:01JBQ5Z7Y2V9T3K8M4N6P0R1S2')
            ->and(json_decode(base64UrlDecode($header), true))->toBe(['alg' => 'ES256', 'kid' => 'KEY1234567'])
            ->and(json_decode(base64UrlDecode($claims), true)['iss'])->toBe('TEAM123456')
            ->and(openssl_verify("{$header}.{$claims}", joseToDer(base64UrlDecode($signature)), $publicKey, OPENSSL_ALGO_SHA256))->toBe(1)
            ->and($request->data())->toBe([
                'aps' => [
                    'alert' => ['title' => 'FT 2026/41 venceu há 3 dias', 'body' => 'Padaria Cazenga tem 125 000,00 AOA por pagar.'],
                    'sound' => 'default',
                    'thread-id' => 'invoice_overdue',
                ],
                'topic' => 'invoice_overdue',
                'workspace' => $workspace->public_id,
                'target' => ['type' => 'document', 'public_id' => '01JBQ5Z7Y2V9T3K8M4N6P0R1S2', 'label' => 'FT 2026/41'],
            ]);

        return true;
    });
});

test('someone in two companies is told which one it is about', function () {
    configureApns();
    Http::fake(['*' => Http::response(status: 200)]);

    $user = User::factory()->withWorkspace('Padaria Central')->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Accountant,
    ]);
    PushDevice::factory()->for($user)->create();

    Notification::sendNow($user, new CustomerOverCreditLimit('01JBQ5Z7Y2V9T3K8M4N6P0R1S3', 'Mavinga & Filhos', 900_00, 500_00, 'AOA', (int) $user->current_workspace_id), [PushChannel::class]);

    Http::assertSent(fn (Request $request): bool => $request['aps']['alert']['subtitle'] === 'Padaria Central'
        && $request['target'] === ['type' => 'customer', 'public_id' => '01JBQ5Z7Y2V9T3K8M4N6P0R1S3', 'label' => 'Mavinga & Filhos']);
});

test('a notification sent the usual way reaches the phone too', function () {
    configureApns();
    Http::fake(['*' => Http::response(status: 200)]);

    $user = User::factory()->withWorkspace()->create();
    PushDevice::factory()->for($user)->create();

    Notification::sendNow($user, new DocumentRejectedByAgt('01JBQ5Z7Y2V9T3K8M4N6P0R1S4', 'FT 2026/7', (int) $user->current_workspace_id, []));

    Http::assertSent(fn (Request $request): bool => $request['aps']['alert']['title'] === 'FT 2026/7 recusado pela AGT');
});

test('a phone Apple no longer knows is forgotten, and a hiccup is not', function (int $status, array $body, bool $forgotten) {
    configureApns();
    Http::fake(['*' => Http::response($body, $status)]);

    $user = User::factory()->withWorkspace()->create();
    PushDevice::factory()->for($user)->create();

    Notification::sendNow($user, overdueNotice($user), [PushChannel::class]);

    expect(PushDevice::query()->exists())->toBe(! $forgotten);
})->with([
    'unregistered' => [410, ['reason' => 'Unregistered'], true],
    'bad token' => [400, ['reason' => 'BadDeviceToken'], true],
    'wrong app' => [400, ['reason' => 'DeviceTokenNotForTopic'], true],
    'too many requests' => [429, ['reason' => 'TooManyRequests'], false],
    'apple is down' => [503, ['reason' => 'ServiceUnavailable'], false],
]);
