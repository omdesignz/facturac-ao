<?php

namespace App\Billing\Contracts;

use App\Billing\Data\GatewayAccessTokenData;

interface WiPayTokenStore
{
    public function validToken(string $clientFingerprint, string $scope): ?GatewayAccessTokenData;

    public function remember(string $clientFingerprint, GatewayAccessTokenData $token): void;

    public function invalidate(string $clientFingerprint, string $scope, string $token): void;

    /** @return list<string> */
    public function signatureKeys(string $clientFingerprint): array;
}
