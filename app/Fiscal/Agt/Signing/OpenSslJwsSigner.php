<?php

namespace App\Fiscal\Agt\Signing;

use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Fiscal\Agt\Support\CanonicalJson;

final readonly class OpenSslJwsSigner implements JwsSigner
{
    public function __construct(
        private SigningKeyResolver $keyResolver,
        private CanonicalJson $canonicalJson,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload, string $keyReference): string
    {
        $resolvedKey = $this->keyResolver->resolve($keyReference);
        $configuredHeader = config('agt.signing.jws_header', [
            'typ' => 'JOSE',
            'alg' => 'RS256',
        ]);
        $header = is_array($configuredHeader) ? $configuredHeader : [];
        $header['typ'] = 'JOSE';
        $header['alg'] = 'RS256';

        $protectedHeader = $this->base64Url($this->canonicalJson->encode($header));
        $encodedPayload = $this->base64Url($this->canonicalJson->encode($payload));
        $signingInput = "{$protectedHeader}.{$encodedPayload}";
        $signed = openssl_sign(
            $signingInput,
            $signature,
            $resolvedKey->privateKeyPem,
            OPENSSL_ALGO_SHA256,
        );

        if (! $signed) {
            throw new SigningKeyUnavailable('Não foi possível assinar o pedido com a chave indicada.');
        }

        return "{$signingInput}.{$this->base64Url($signature)}";
    }

    public function fingerprint(string $keyReference): string
    {
        return $this->keyResolver->resolve($keyReference)->fingerprint;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
