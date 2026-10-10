<?php

namespace App\Fiscal;

/** The accepted permit and ledger behind the send seam: the same calls in the same order. */
final class LegacyAiSendAuthority implements \JsonSerializable, AiSendAuthority
{
    public function __construct(private readonly AssistantProviderInvocation $invocation,
        private readonly AssistantProviderPermit $permit, private readonly AssistantProviderLedger $ledger) {}

    public function input(): ProviderIntentInput
    {
        return $this->permit->input;
    }

    public function permissions(): array
    {
        return $this->permit->permissions;
    }

    public function body(): string
    {
        return $this->permit->body;
    }

    public function matchesBody(#[\SensitiveParameter] string $body): bool
    {
        return $this->permit->matchesBody($body);
    }

    public function consume(): void
    {
        $this->permit->consume($this->invocation, $this->ledger);
    }

    public function recheck(): void
    {
        $this->permit->recheck($this->invocation, $this->ledger);
    }

    public function authorizeSend(): void
    {
        $this->permit->markTransmission($this->invocation, $this->ledger);
    }

    public function transmissionStarted(): bool
    {
        return $this->permit->transmissionStarted();
    }

    public function gate(): void
    {
        $this->invocation->permissions();
        $this->ledger->gates($this->invocation);
    }

    public function finalize(?array $usage, bool $received, bool $anomaly, bool $notSent): void
    {
        $this->ledger->finalize($this->permit->attemptId, $usage, $received, $anomaly, $this->invocation->context, $notSent);
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Send authority cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Send authority cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __clone() {}
}
