<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class CreateEmisReferenceData
{
    public function __construct(
        public string $idempotencyKey,
        public string $merchantReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public CarbonImmutable $requestedExpiresAt,
    ) {}
}
