<?php

namespace App\Billing\Data;

use SensitiveParameter;

final readonly class HostedPaymentData
{
    public function __construct(
        public string $providerId,
        #[SensitiveParameter] public string $checkoutUrl,
        public string $payloadSha256,
    ) {}
}
