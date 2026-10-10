<?php

namespace App\Fiscal;

/** Fixed provider adapter; observations remain untrusted until the owning session authenticates origin. */
final readonly class AnthropicCredentialVerificationAdapter implements TenantAiVerificationAdapter
{
    public function __construct(private TenantAiVerificationTransport $transport) {}

    public function observe(TenantAiCredentialVerifier $session, VerificationRequestPermit $permit, TenantAiVerificationContext $context,
        TenantAiVerificationPolicy $policy, #[\SensitiveParameter] string $secret): CredentialVerificationEvidence
    {
        return $this->transport->exchange($session, $permit, $context, $policy, $secret);
    }
}
