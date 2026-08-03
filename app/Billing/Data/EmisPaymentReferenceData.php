<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class EmisPaymentReferenceData
{
    public function __construct(
        public string $providerReferenceId,
        public string $entity,
        public string $reference,
        public int $amountMinor,
        public string $currencyCode,
        public CarbonImmutable $expiresAt,
        public string $payloadSha256,
    ) {}
}
