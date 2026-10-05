<?php

namespace App\Billing\WiPay;

use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Data\GatewayAccessTokenData;
use App\Models\WiPayToken;

final class DatabaseWiPayTokenStore implements WiPayTokenStore
{
    public function validToken(string $clientFingerprint, string $scope): ?GatewayAccessTokenData
    {
        $token = WiPayToken::query()->where('client_fingerprint', $clientFingerprint)
            ->where('scope', $scope)->where('expires_at', '>', now()->addMinute())
            ->latest('id')->first();

        return $token === null ? null : new GatewayAccessTokenData($token->access_token, $token->scope, $token->expires_at);
    }

    public function remember(string $clientFingerprint, GatewayAccessTokenData $token): void
    {
        WiPayToken::query()->updateOrCreate([
            'token_fingerprint' => hash('sha256', $clientFingerprint.'|'.$token->scope.'|'.$token->value),
        ], [
            'client_fingerprint' => $clientFingerprint,
            'scope' => $token->scope,
            'access_token' => $token->value,
            'expires_at' => $token->expiresAt,
        ]);
    }

    public function invalidate(string $clientFingerprint, string $scope, string $token): void
    {
        WiPayToken::query()->where('client_fingerprint', $clientFingerprint)->where('scope', $scope)
            ->where('token_fingerprint', hash('sha256', $clientFingerprint.'|'.$scope.'|'.$token))
            ->update(['expires_at' => now()->subSecond()]);
    }

    /** @return list<string> */
    public function signatureKeys(string $clientFingerprint): array
    {
        return array_values(WiPayToken::query()->where('client_fingerprint', $clientFingerprint)
            ->where('scope', 'signature')->where('expires_at', '>', now()->subDay())
            ->latest('id')->limit(10)->get()->map(fn (WiPayToken $token): string => $token->access_token)->all());
    }
}
