<?php

namespace App\Fiscal;

/** Internal observation contract; neither adapters nor returned observations grant lifecycle authority. */
interface TenantAiVerificationAdapter
{
    public function observe(TenantAiCredentialVerifier $session, VerificationRequestPermit $permit, TenantAiVerificationContext $context,
        TenantAiVerificationPolicy $policy, #[\SensitiveParameter] string $secret): CredentialVerificationEvidence;
}
