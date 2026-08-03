<?php

namespace App\Billing\Data;

use App\EmisPaymentReferenceStatus;
use Carbon\CarbonImmutable;

final readonly class EmisPaymentStatusData
{
    public function __construct(
        public EmisPaymentReferenceStatus $status,
        public ?string $providerEventId,
        public int $amountMinor,
        public string $currencyCode,
        public CarbonImmutable $occurredAt,
        public string $payloadSha256,
    ) {}
}
