<?php

namespace App\Billing\WiPay;

use SensitiveParameter;

final class WiPaySignatureVerifier
{
    public function verify(string $rawBody, string $signature, #[SensitiveParameter] string $key): bool
    {
        return $key !== '' && preg_match('/\A[a-fA-F0-9]{64}\z/', $signature) === 1
            && hash_equals(hash_hmac('sha256', $rawBody, $key), strtolower($signature));
    }
}
