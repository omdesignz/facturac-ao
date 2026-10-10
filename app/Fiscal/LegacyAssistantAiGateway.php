<?php

namespace App\Fiscal;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Delegation preserves all accepted admission, disclosure, ledger and transport ownership. */
final readonly class LegacyAssistantAiGateway implements VapAiGateway
{
    public function __construct(private AnthropicIntentPlanner $planner) {}

    public function infer(AiInferenceRequest $request): AiInferenceResult
    {
        $identity = AiModelIdentity::LegacyAnthropicIntent;
        try {
            $invocation = $request->invocation();
            $plan = $this->planner->plan(AssistantPlan::plannerInput($invocation->input, $invocation->context->execution->permissions), $invocation);

            return AiInferenceResult::proposedPlan($identity, $plan);
        } catch (\Throwable $error) {
            $failure = $error instanceof HttpExceptionInterface
                ? (AiGatewayFailure::tryFrom($error->getStatusCode()) ?? AiGatewayFailure::Unavailable)
                : AiGatewayFailure::Unavailable;

            return AiInferenceResult::failed($identity, $failure);
        }
    }
}
