<?php

namespace App\Fiscal;

/** Safe application result; no account claims, receipts, revisions or upstream errors. */
final readonly class TenantAiVerificationResult
{
    public function __construct(public string $status, public ?string $operationId = null) {}
}
