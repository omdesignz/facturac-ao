<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;

/** Compare-and-swap assertions only; this value never grants authority. */
final readonly class TenantAiVerificationRequest
{
    public function __construct(
        public string $connectionId,
        public string $credentialId,
        public int $settingsRevision,
        public int $connectionRevision,
        public ?string $selectedVersionId,
        public int $activeGeneration,
    ) {
        foreach ([$connectionId, $credentialId, ...($selectedVersionId === null ? [] : [$selectedVersionId])] as $id) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
        }
        if ($settingsRevision < 1 || $connectionRevision < 1 || $activeGeneration < 0
            || max($settingsRevision, $connectionRevision, $activeGeneration) === PHP_INT_MAX) {
            throw new TenantAiStorageUnavailable;
        }
    }
}
