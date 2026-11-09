<?php

use App\Providers\FortifyServiceProvider;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationObject;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorData;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

function configureAndroidPasskeyAssociation(mixed $package, mixed $fingerprints): void
{
    config([
        'services.android.passkey_package' => $package,
        'services.android.passkey_sha256_cert_fingerprints' => $fingerprints,
    ]);
    app()->call([app()->getProvider(FortifyServiceProvider::class), 'boot']);
}

function androidTestFingerprint(string $byte = 'AB'): string
{
    return implode(':', array_fill(0, 32, $byte));
}

/** @return array{CredentialRecord, AuthenticatorAssertionResponse, PublicKeyCredentialRequestOptions} */
function signedAndroidPasskeyAssertion(string $origin, string $defect = ''): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $coordinates = openssl_pkey_get_details($key)['ec'];
    // COSE EC2 / ES256 / P-256 with the generated public x and y coordinates.
    $publicKey = hex2bin('a5010203262001215820').$coordinates['x'].hex2bin('225820').$coordinates['y'];
    $source = new CredentialRecord(
        'android-credential', 'public-key', ['internal'], 'none',
        new EmptyTrustPath, Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        $publicKey, 'test-user', 0,
    );
    $options = new PublicKeyCredentialRequestOptions(
        random_bytes(32), rpId: Passkeys::relyingPartyId(),
        allowCredentials: [$source->getPublicKeyCredentialDescriptor()], userVerification: 'required',
    );
    $clientData = [
        'type' => 'webauthn.get',
        'challenge' => Base64UrlSafe::encodeUnpadded($defect === 'challenge' ? random_bytes(32) : $options->challenge),
        'origin' => $origin,
        'crossOrigin' => false,
    ];
    $client = new CollectedClientData(json_encode($clientData, JSON_THROW_ON_ERROR), $clientData);
    $rpHash = hash('sha256', $defect === 'rp' ? 'attacker.test' : $options->rpId, true);
    $flags = chr($defect === 'verification' ? 1 : 5);
    $authData = $rpHash.$flags.pack('N', 1);
    openssl_sign($authData.hash('sha256', $client->rawData, true), $signature, $key, OPENSSL_ALGO_SHA256);
    if ($defect === 'signature') {
        $signature[10] = $signature[10] ^ chr(1);
    }
    $response = new AuthenticatorAssertionResponse(
        $client, new AuthenticatorData($authData, $rpHash, $flags, 1, null, null),
        $signature, $source->userHandle,
    );

    return [$source, $response, $options];
}

beforeEach(function () {
    configureAndroidPasskeyAssociation(null, []);
});

test('android association is disabled until an app and certificate are configured', function () {
    $this->get('/.well-known/assetlinks.json')->assertNotFound();
    expect(Passkeys::allowedOrigins())->toBe(config('fortify.passkeys.allowed_origins'));
});

test('android association publishes only credential sharing and normalizes rotating certificates', function () {
    configureAndroidPasskeyAssociation('ao.facturac.app', [
        ' '.androidTestFingerprint('ab').' ', androidTestFingerprint('CD'), androidTestFingerprint('AB'),
    ]);

    $this->get('/.well-known/assetlinks.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([[
            'relation' => ['delegate_permission/common.get_login_creds'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'ao.facturac.app',
                'sha256_cert_fingerprints' => [androidTestFingerprint(), androidTestFingerprint('CD')],
            ],
        ]]);

    expect(Passkeys::allowedOrigins())->toBe([
        ...config('fortify.passkeys.allowed_origins'),
        'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s',
        'android:apk-key-hash:zc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc0',
    ]);
});

test('invalid android configuration grants no trust', function (mixed $package, mixed $fingerprints) {
    configureAndroidPasskeyAssociation($package, $fingerprints);
    $this->get('/.well-known/assetlinks.json')->assertNotFound();
    expect(Passkeys::allowedOrigins())->toBe(config('fortify.passkeys.allowed_origins'));
})->with([
    'missing package' => [null, [androidTestFingerprint()]],
    'empty package' => ['', [androidTestFingerprint()]],
    'wildcard package' => ['ao.facturac.*', [androidTestFingerprint()]],
    'invalid package' => ['ao..facturac', [androidTestFingerprint()]],
    'missing certificate' => ['ao.facturac.app', []],
    'null certificates' => ['ao.facturac.app', null],
    'wrong container' => ['ao.facturac.app', androidTestFingerprint()],
    'sha1 certificate' => ['ao.facturac.app', [implode(':', array_fill(0, 20, 'AB'))]],
    'bad hex' => ['ao.facturac.app', [androidTestFingerprint('ZZ')]],
    'wildcard certificate' => ['ao.facturac.app', ['*']],
    'malformed rotation entry' => ['ao.facturac.app', [androidTestFingerprint(), '']],
    'non-string certificate' => ['ao.facturac.app', [123]],
]);

test('removing certificate configuration revokes trust after provider boot', function () {
    configureAndroidPasskeyAssociation('ao.facturac.app', [androidTestFingerprint()]);
    configureAndroidPasskeyAssociation(null, []);

    expect(Passkeys::allowedOrigins())->toBe(config('fortify.passkeys.allowed_origins'));
    $this->get('/.well-known/assetlinks.json')->assertNotFound();
});

test('real assertion validation accepts configured android and existing web origins', function (string $origin) {
    configureAndroidPasskeyAssociation('ao.facturac.app', [androidTestFingerprint()]);
    [$source, $response, $options] = signedAndroidPasskeyAssertion($origin);

    $verified = WebAuthn::assertionValidator()->check($source, $response, $options, Passkeys::relyingPartyId(), $source->userHandle);

    expect($verified->counter)->toBe(1);
})->with([
    'android' => 'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s',
    'web and ios' => 'https://facturac.test',
]);

test('real assertion validation rejects unlisted and altered certificate origins', function (string $origin) {
    configureAndroidPasskeyAssociation('ao.facturac.app', [androidTestFingerprint()]);
    [$source, $response, $options] = signedAndroidPasskeyAssertion($origin);

    expect(fn () => WebAuthn::assertionValidator()->check($source, $response, $options, Passkeys::relyingPartyId(), $source->userHandle))
        ->toThrow(AuthenticatorResponseVerificationException::class);
})->with([
    'another certificate' => 'android:apk-key-hash:zc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc0',
    'padded certificate' => 'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s=',
    'origin suffix' => 'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s/evil',
    'unlisted web' => 'https://attacker.test',
]);

test('trusted android origins still require a valid signature challenge rp and user verification', function (string $defect) {
    configureAndroidPasskeyAssociation('ao.facturac.app', [androidTestFingerprint()]);
    [$source, $response, $options] = signedAndroidPasskeyAssertion(
        'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s', $defect,
    );

    expect(fn () => WebAuthn::assertionValidator()->check($source, $response, $options, Passkeys::relyingPartyId(), $source->userHandle))
        ->toThrow(AuthenticatorResponseVerificationException::class);
})->with(['signature', 'challenge', 'rp', 'verification']);

test('registration validates the configured android origin and rejects another certificate', function (bool $trusted) {
    configureAndroidPasskeyAssociation('ao.facturac.app', [androidTestFingerprint()]);
    $origin = $trusted
        ? 'android:apk-key-hash:q6urq6urq6urq6urq6urq6urq6urq6urq6urq6urq6s'
        : 'android:apk-key-hash:zc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc0';
    [$source] = signedAndroidPasskeyAssertion($origin);
    $options = new PublicKeyCredentialCreationOptions(
        new PublicKeyCredentialRpEntity('facturac.ao', Passkeys::relyingPartyId()),
        new PublicKeyCredentialUserEntity('ana@example.test', $source->userHandle, 'Ana'),
        random_bytes(32), [PublicKeyCredentialParameters::createPk(-7)],
        new AuthenticatorSelectionCriteria(userVerification: 'required'),
    );
    $clientData = [
        'type' => 'webauthn.create',
        'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge),
        'origin' => $origin,
        'crossOrigin' => false,
    ];
    $client = new CollectedClientData(json_encode($clientData, JSON_THROW_ON_ERROR), $clientData);
    $rpHash = hash('sha256', Passkeys::relyingPartyId(), true);
    $authData = new AuthenticatorData(
        '', $rpHash, chr(69), 0, $source->getAttestedCredentialData(), null,
    );
    $response = new AuthenticatorAttestationResponse($client, new AttestationObject(
        '', AttestationStatement::createNone('none', [], new EmptyTrustPath), $authData,
    ));
    $validate = fn () => WebAuthn::attestationValidator()->check($response, $options, Passkeys::relyingPartyId());

    if ($trusted) {
        expect($validate()->publicKeyCredentialId)->toBe($source->publicKeyCredentialId);
    } else {
        expect($validate)->toThrow(AuthenticatorResponseVerificationException::class);
    }
})->with([true, false]);
