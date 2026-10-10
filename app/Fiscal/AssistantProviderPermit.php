<?php

namespace App\Fiscal;

/** A single admitted canonical request; possession never replaces fresh authority. */
final class AssistantProviderPermit implements \JsonSerializable
{
    private readonly string $inputDigest;

    private bool $consumed = false;

    private bool $transmissionStarted = false;

    /** @param list<string> $permissions
     * @param  \Fiber<mixed, mixed, mixed, mixed>|null  $fiber
     */
    private function __construct(private readonly AssistantProviderInvocation $invocation,
        public readonly ProviderIntentInput $input, #[\SensitiveParameter] public readonly string $body,
        public readonly array $permissions, public readonly string $attemptId, private readonly ?\Fiber $fiber)
    {
        $this->inputDigest = hash('sha256', $body);
    }

    public static function admit(AssistantProviderInvocation $invocation, AssistantIntentGateway $gateway, AssistantProviderLedger $ledger): self
    {
        $invocation->requireHttp();
        $permissions = $invocation->permissions();
        $input = ProviderIntentInput::fromInput($invocation->input, $permissions);
        $body = $gateway->prepare($input);

        return new self($invocation, $input, $body, $permissions, $ledger->reserve($invocation, $body), \Fiber::getCurrent());
    }

    public function consume(AssistantProviderInvocation $invocation, AssistantProviderLedger $ledger): void
    {
        abort_if($this->consumed, 503);
        $this->consumed = true;
        $ledger->assertAdmitted($this->attemptId, $invocation);
        $this->recheck($invocation, $ledger);
    }

    public function recheck(AssistantProviderInvocation $invocation, AssistantProviderLedger $ledger): void
    {
        abort_unless($this->consumed && $this->invocation === $invocation && $this->fiber === \Fiber::getCurrent()
            && request()->attributes->get('assistant_context') === $invocation->context
            && request()->attributes->get('assistant_guard') === $invocation->guard && request()->routeIs('assistant.store'), 503);
        abort_if(array_diff($this->permissions, $invocation->permissions()) !== [], 503);
        $ledger->gates($invocation);
        $invocation->remaining();
    }

    public function markTransmission(AssistantProviderInvocation $invocation, AssistantProviderLedger $ledger): void
    {
        $this->recheck($invocation, $ledger);
        abort_if($this->transmissionStarted, 503);
        $ledger->assertAdmitted($this->attemptId, $invocation);
        $this->transmissionStarted = true;
    }

    public function matchesBody(#[\SensitiveParameter] string $body): bool
    {
        return hash_equals($this->inputDigest, hash('sha256', $body));
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Provider permits cannot be serialized.');
    }

    public function transmissionStarted(): bool
    {
        return $this->transmissionStarted;
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Provider permits cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __clone() {}
}
