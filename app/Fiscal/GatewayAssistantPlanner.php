<?php

namespace App\Fiscal;

/** Opt-in application adapter; the accepted production AssistantPlanner binding stays unchanged. */
final readonly class GatewayAssistantPlanner implements AssistantPlanner
{
    public function __construct(private VapAiGateway $gateway) {}

    public function plan(#[\SensitiveParameter] array $input, ?AssistantProviderInvocation $invocation = null): string
    {
        abort_unless($invocation instanceof AssistantProviderInvocation, 503);

        return $this->gateway->infer(new AiInferenceRequest($invocation))->untrustedPlan();
    }
}
