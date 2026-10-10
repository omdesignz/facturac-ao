<?php

namespace App\Fiscal;

/** Explicit local invocation only; authority is not model context or a transmission permit. */
final readonly class AiInferenceRequest implements \JsonSerializable
{
    public function __construct(private AssistantProviderInvocation $invocation) {}

    public function invocation(): AssistantProviderInvocation
    {
        return $this->invocation;
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Inference requests cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Inference requests cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __clone() {}
}
