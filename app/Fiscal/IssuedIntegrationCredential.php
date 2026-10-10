<?php

namespace App\Fiscal;

final class IssuedIntegrationCredential
{
    public function __construct(public readonly string $integrationPublicId, public readonly string $credentialPublicId, #[\SensitiveParameter] private ?string $secret) {}

    public function __clone(): void
    {
        throw new \LogicException('Credential disclosure cannot be cloned.');
    }

    public function revealOnce(): string
    {
        if ($this->secret === null) {
            throw new \LogicException('Credential was already disclosed.');
        }
        $secret = $this->secret;
        $this->secret = null;

        return $secret;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['integration' => $this->integrationPublicId, 'credential' => $this->credentialPublicId];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Credential disclosure cannot be persisted.');
    }
}
