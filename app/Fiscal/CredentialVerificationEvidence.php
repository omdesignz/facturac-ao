<?php

namespace App\Fiscal;

/** Provider observations are data, never promotion authority. */
final readonly class CredentialVerificationEvidence
{
    public function __construct(
        public string $outcome,
        public ?string $model = null,
        public ?string $organization = null,
        public ?string $workspace = null,
    ) {}
}
