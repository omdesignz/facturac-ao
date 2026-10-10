<?php

namespace App\Fiscal;

/** Request-local authority; never part of the provider projection. */
final class AssistantProviderInvocation implements \JsonSerializable
{
    private ?AssistantProviderPermit $permit = null;

    private function __construct(public readonly AssistantInteractionContext $context, public readonly AssistantInput $input,
        public readonly AssistantExecutionGuard $guard, public readonly float $started) {}

    public static function forInteraction(AssistantInteractionContext $context, AssistantInput $input, AssistantExecutionGuard $guard): self
    {
        return new self($context, $input, $guard, hrtime(true) / 1e9);
    }

    public function retain(AssistantProviderPermit $permit): void
    {
        abort_if($this->permit !== null, 503);
        $permit->recheck($this, new AssistantProviderLedger);
        $this->permit = $permit;
    }

    public function verify(): void
    {
        $this->guard->check();
        $this->permit?->recheck($this, new AssistantProviderLedger);
    }

    public function requireHttp(): void
    {
        abort_if(app()->runningInConsole() && ! app()->runningUnitTests(), 503);
        abort_unless(request()->attributes->get('assistant_context') === $this->context
            && request()->attributes->get('assistant_guard') === $this->guard
            && request()->isMethod('POST') && request()->routeIs('assistant.store'), 503);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $fresh = $this->context->fresh();
        $permissions = array_values(array_intersect($this->context->execution->permissions, $fresh->permissions));
        abort_if(array_intersect($permissions, AssistantPlan::PERMISSIONS) === [], 503);
        $this->remaining();

        return $permissions;
    }

    public function remaining(): float
    {
        abort_if(connection_aborted() !== 0, 503);
        $remaining = min(10 - (hrtime(true) / 1e9 - $this->started), $this->guard->remaining());
        abort_if($remaining <= 0, 503);

        return $remaining;
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Provider invocation cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Provider input cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __clone() {}
}
