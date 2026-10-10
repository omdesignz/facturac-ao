<?php

namespace App\Fiscal;

/** An untrusted local intent plan or a payload-free failure; never factual response data. */
final readonly class AiInferenceResult implements \JsonSerializable
{
    private function __construct(public AiModelIdentity $identity, #[\SensitiveParameter] private ?string $plan,
        public ?AiGatewayFailure $failure) {}

    public static function proposedPlan(AiModelIdentity $identity, #[\SensitiveParameter] string $plan): self
    {
        AssistantJson::object($plan, 4096, 503);

        return new self($identity, $plan, null);
    }

    public static function failed(AiModelIdentity $identity, AiGatewayFailure $failure): self
    {
        return new self($identity, null, $failure);
    }

    /** The unchanged application plan validator must still run before any capability. */
    public function untrustedPlan(): string
    {
        if ($this->failure !== null) {
            abort($this->failure->value, '', $this->failure === AiGatewayFailure::QuotaExceeded ? ['Retry-After' => '60'] : []);
        }
        abort_unless(is_string($this->plan), 503);

        return $this->plan;
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Inference results cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Inference results cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
