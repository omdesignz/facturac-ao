<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

require_once __DIR__.'/../PosFixtures.php';

/*
 * The signature itself is the package's to check, and is faked here. What these
 * tests prove is the phone's side: which challenge an answer may use, for whom,
 * once, and what a verified passkey is allowed to stand in for.
 */

function base64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * A well-formed answer to a sign-in or confirmation challenge.
 *
 * @return array<string, mixed>
 */
function passkeyAssertion(): array
{
    $id = random_bytes(16);

    return [
        'id' => base64Url($id),
        'rawId' => base64Url($id),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => base64Url(json_encode(['type' => 'webauthn.get', 'challenge' => base64Url(random_bytes(32)), 'origin' => config('app.url')])),
            'authenticatorData' => base64Url(random_bytes(32).chr(0x05).pack('N', 1)),
            'signature' => base64Url(random_bytes(64)),
            'userHandle' => base64Url('1'),
        ],
    ];
}

/**
 * A well-formed answer to a registration challenge.
 *
 * @return array<string, mixed>
 */
function passkeyAttestation(): array
{
    $id = random_bytes(16);
    $authenticatorData = random_bytes(32).chr(0x05).pack('N', 0);

    return [
        'id' => base64Url($id),
        'rawId' => base64Url($id),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => base64Url(json_encode(['type' => 'webauthn.create', 'challenge' => base64Url(random_bytes(32)), 'origin' => config('app.url')])),
            'attestationObject' => base64Url("\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x58\x25".$authenticatorData),
            'transports' => ['internal'],
        ],
    ];
}

function passkeyFor(User $user, string $name = 'iPhone da Ana'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => base64Url(random_bytes(16)),
        'credential' => ['id' => 'stored'],
    ]);
}

/**
 * The package's check, answering with this passkey and recording the challenge it was given.
 *
 * @param  list<string>  $challenges
 */
function passkeyVerifies(?Passkey $passkey, array &$challenges = []): void
{
    test()->mock(VerifyPasskey::class, function ($mock) use ($passkey, &$challenges): void {
        $mock->shouldReceive('__invoke')->andReturnUsing(function ($credential, PublicKeyCredentialRequestOptions $options, ?User $user = null) use ($passkey, &$challenges): Passkey {
            $challenges[] = base64Url($options->challenge);

            if ($passkey === null || ($user !== null && $passkey->user_id !== $user->id)) {
                throw InvalidPasskeyException::make('Passkey not recognized.');
            }

            return $passkey;
        });
    });
}

beforeEach(function () {
    Queue::fake();
});

test('the phone signs in with a passkey and gets its own token', function () {
    $user = User::factory()->withWorkspace()->create();
    $passkey = passkeyFor($user);
    $challenges = [];
    passkeyVerifies($passkey, $challenges);

    $options = $this->postJson(route('mobile.v1.sessions.passkey-options'))
        ->assertOk()
        ->assertJsonPath('data.options.rpId', parse_url(config('app.url'), PHP_URL_HOST))
        ->assertJsonPath('data.options.userVerification', 'required')
        ->json('data');

    $this->postJson(route('mobile.v1.sessions.passkey'), [
        'challenge_id' => $options['challenge_id'],
        'credential' => passkeyAssertion(),
        'device_name' => 'iPhone da Ana',
    ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    expect($challenges)->toBe([$options['options']['challenge']])
        ->and($user->tokens()->sole()->can(SignInMobileDevice::TOKEN_ABILITY))->toBeTrue();
});

test('a sign-in challenge answers once', function () {
    $passkey = passkeyFor(User::factory()->withWorkspace()->create());
    passkeyVerifies($passkey);
    $challengeId = $this->postJson(route('mobile.v1.sessions.passkey-options'))->json('data.challenge_id');
    $answer = ['challenge_id' => $challengeId, 'credential' => passkeyAssertion(), 'device_name' => 'iPhone'];

    $this->postJson(route('mobile.v1.sessions.passkey'), $answer)->assertCreated();

    $this->postJson(route('mobile.v1.sessions.passkey'), $answer)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    $this->postJson(route('mobile.v1.sessions.passkey'), [...$answer, 'challenge_id' => 'made-up'])
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    expect($passkey->user->tokens()->count())->toBe(1);
});

test('a passkey the package refuses gives no token', function () {
    passkeyVerifies(null);
    $challengeId = $this->postJson(route('mobile.v1.sessions.passkey-options'))->json('data.challenge_id');

    $this->postJson(route('mobile.v1.sessions.passkey'), ['challenge_id' => $challengeId, 'credential' => passkeyAssertion(), 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');
});

test('the phone may write the answer in snake_case, as it writes every request', function () {
    $passkey = passkeyFor(User::factory()->withWorkspace()->create());
    passkeyVerifies($passkey);
    $challengeId = $this->postJson(route('mobile.v1.sessions.passkey-options'))->json('data.challenge_id');
    $answer = passkeyAssertion();

    $this->postJson(route('mobile.v1.sessions.passkey'), [
        'challenge_id' => $challengeId,
        'credential' => [
            'id' => $answer['id'],
            'raw_id' => $answer['rawId'],
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $answer['response']['clientDataJSON'],
                'authenticator_data' => $answer['response']['authenticatorData'],
                'signature' => $answer['response']['signature'],
                'user_handle' => $answer['response']['userHandle'],
            ],
        ],
        'device_name' => 'iPhone',
    ])->assertCreated();
});

test('a passkey cannot sign in an account whose email is unconfirmed', function () {
    $passkey = passkeyFor(User::factory()->withWorkspace()->unverified()->create());
    passkeyVerifies($passkey);
    $challengeId = $this->postJson(route('mobile.v1.sessions.passkey-options'))->json('data.challenge_id');

    $this->postJson(route('mobile.v1.sessions.passkey'), ['challenge_id' => $challengeId, 'credential' => passkeyAssertion(), 'device_name' => 'iPhone'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
});

test('adding a passkey from the phone asks for the password first', function () {
    $user = User::factory()->withWorkspace()->create(['password' => Hash::make('a-very-good-password')]);
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;

    $this->withToken($token)->postJson(route('mobile.v1.passkeys.options'), ['password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    $options = $this->withToken($token)->postJson(route('mobile.v1.passkeys.options'), ['password' => 'a-very-good-password'])
        ->assertOk()
        ->assertJsonPath('data.options.user.name', $user->email)
        ->json('data');

    $this->mock(StorePasskey::class, function ($mock) use ($user, $options): void {
        $mock->shouldReceive('__invoke')->once()->andReturnUsing(
            function (User $owner, string $name, $credential, PublicKeyCredentialCreationOptions $created) use ($user, $options): Passkey {
                expect($owner->is($user))->toBeTrue()
                    ->and(base64Url($created->challenge))->toBe($options['options']['challenge']);

                return passkeyFor($owner, $name);
            },
        );
    });

    $this->withToken($token)->postJson(route('mobile.v1.passkeys.store'), [
        'challenge_id' => $options['challenge_id'],
        'credential' => passkeyAttestation(),
        'name' => 'iPhone da Ana',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'iPhone da Ana');

    $this->withToken($token)->getJson(route('mobile.v1.passkeys.index'))
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.credential');

    $this->withToken($token)->getJson(route('mobile.v1.me'))->assertJsonPath('data.has_passkey', true);
});

test('a registration challenge made for one person cannot add a passkey to another', function () {
    $ana = User::factory()->withWorkspace()->create(['password' => Hash::make('a-very-good-password')]);
    $other = User::factory()->withWorkspace()->create();
    $challengeId = $this->withToken($ana->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken)
        ->postJson(route('mobile.v1.passkeys.options'), ['password' => 'a-very-good-password'])
        ->json('data.challenge_id');
    app('auth')->forgetGuards();

    $this->withToken($other->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken)
        ->postJson(route('mobile.v1.passkeys.store'), ['challenge_id' => $challengeId, 'credential' => passkeyAttestation(), 'name' => 'Intruso'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    expect($other->passkeys()->count())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function passkeyIssuingCompany(): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'FTAPP',
        'document_type' => FiscalDocumentType::Invoice,
    ]);

    return [
        ...$company,
        'token' => $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'customer' => Customer::factory()->create([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
        ]),
        'item' => posItem($company),
    ];
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $proof
 * @return array<string, mixed>
 */
function passkeyInvoicePayload(array $company, array $proof): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => 22_800,
        'issue' => true,
        ...$proof,
    ];
}

test('a passkey confirms the person at issue in place of the password', function () {
    $company = passkeyIssuingCompany();
    $passkey = passkeyFor($company['owner']);
    passkeyVerifies($passkey);

    $challengeId = $this->withToken($company['token'])->postJson(route('mobile.v1.passkeys.confirmation'))
        ->assertOk()
        ->assertJsonCount(1, 'data.options.allowCredentials')
        ->json('data.challenge_id');
    $proof = ['passkey' => ['challenge_id' => $challengeId, 'credential' => passkeyAssertion()]];

    $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), passkeyInvoicePayload($company, $proof))
        ->assertCreated()
        ->assertJsonPath('data.is_issued', true);

    // The same answer again is a replay, not a second confirmation.
    $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), passkeyInvoicePayload($company, $proof))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    expect(FiscalDocument::query()->whereNotNull('document_no')->count())->toBe(1);
});

test('a refused passkey issues nothing, and someone else\'s passkey is refused', function () {
    $company = passkeyIssuingCompany();
    passkeyFor($company['owner']);
    passkeyVerifies(passkeyFor(User::factory()->create(), 'Outro telefone'));

    $challengeId = $this->withToken($company['token'])->postJson(route('mobile.v1.passkeys.confirmation'))->json('data.challenge_id');

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), passkeyInvoicePayload($company, [
            'passkey' => ['challenge_id' => $challengeId, 'credential' => passkeyAssertion()],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a sign-in challenge cannot confirm an issue', function () {
    $company = passkeyIssuingCompany();
    passkeyVerifies(passkeyFor($company['owner']));
    $challengeId = $this->postJson(route('mobile.v1.sessions.passkey-options'))->json('data.challenge_id');

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), passkeyInvoicePayload($company, [
            'passkey' => ['challenge_id' => $challengeId, 'credential' => passkeyAssertion()],
        ]))
        ->assertJsonPath('error.code', 'PASSKEY_REFUSED');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('without a passkey there is no confirmation challenge, and the password is still needed', function () {
    $company = passkeyIssuingCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.passkeys.confirmation'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'NO_PASSKEY');

    $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), passkeyInvoicePayload($company, []))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('apple learns which app may use this site\'s passkeys', function () {
    $this->get('/.well-known/apple-app-site-association')->assertNotFound();

    config(['services.ios.app_id' => 'ABCDE12345.ao.facturac.app']);

    $this->get('/.well-known/apple-app-site-association')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['webcredentials' => ['apps' => ['ABCDE12345.ao.facturac.app']]]);
});
