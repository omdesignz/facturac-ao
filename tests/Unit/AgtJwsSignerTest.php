<?php

use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Fiscal\Agt\Signing\FilesystemSigningKeyResolver;
use App\Fiscal\Agt\Signing\OpenSslJwsSigner;
use App\Fiscal\Agt\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array{directory: string, public_key: string}
 */
function createAgtSigningKey(string $reference, int $bits = 2048): array
{
    $directory = storage_path('framework/testing/agt-jws-'.Str::uuid());
    $path = $directory.'/'.$reference.'.pem';
    File::ensureDirectoryExists(dirname($path));
    $privateKey = openssl_pkey_new([
        'private_key_bits' => $bits,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($privateKey === false) {
        throw new RuntimeException('Unable to create the synthetic test key.');
    }

    $exported = openssl_pkey_export($privateKey, $privateKeyPem);
    $details = openssl_pkey_get_details($privateKey);

    if (! $exported || ! is_array($details)) {
        throw new RuntimeException('Unable to export the synthetic test key.');
    }

    File::put($path, $privateKeyPem);

    return [
        'directory' => $directory,
        'public_key' => $details['key'],
    ];
}

function decodeAgtJwsSegment(string $segment): string
{
    $padding = strlen($segment) % 4;

    if ($padding > 0) {
        $segment .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

    if ($decoded === false) {
        throw new RuntimeException('Unable to decode the JWS segment.');
    }

    return $decoded;
}

test('it creates a canonical compact RS256 JWS that verifies with the matching public key', function () {
    $key = createAgtSigningKey('software/test');
    config()->set('agt.signing.key_directory', $key['directory']);
    $signer = new OpenSslJwsSigner(
        new FilesystemSigningKeyResolver,
        new CanonicalJson,
    );

    try {
        $jws = $signer->sign([
            'zeta' => 'last',
            'alpha' => [
                'zeta' => 2,
                'alpha' => 1,
            ],
        ], 'software/test');
        $segments = explode('.', $jws);

        expect($segments)->toHaveCount(3)
            ->and($jws)->not->toContain('=')
            ->and(json_decode(decodeAgtJwsSegment($segments[0]), true))->toBe([
                'alg' => 'RS256',
                'typ' => 'JOSE',
            ])
            ->and(decodeAgtJwsSegment($segments[1]))
            ->toBe('{"alpha":{"alpha":1,"zeta":2},"zeta":"last"}')
            ->and(openssl_verify(
                $segments[0].'.'.$segments[1],
                decodeAgtJwsSegment($segments[2]),
                $key['public_key'],
                OPENSSL_ALGO_SHA256,
            ))->toBe(1)
            ->and($signer->fingerprint('software/test'))->toHaveLength(64);
    } finally {
        File::deleteDirectory($key['directory']);
    }
});

test('it rejects weak RSA keys and path traversal references', function () {
    $key = createAgtSigningKey('weak/key', 1024);
    config()->set('agt.signing.key_directory', $key['directory']);
    $resolver = new FilesystemSigningKeyResolver;

    try {
        expect(fn () => $resolver->resolve('weak/key'))
            ->toThrow(SigningKeyUnavailable::class, 'pelo menos 2048 bits')
            ->and(fn () => $resolver->resolve('../outside'))
            ->toThrow(SigningKeyUnavailable::class, 'referência');
    } finally {
        File::deleteDirectory($key['directory']);
    }
});
