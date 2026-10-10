<?php

namespace App\Fiscal;

use Illuminate\Support\Str;

final readonly class AssistantInteraction
{
    public function __construct(private AssistantPlanner $planner, private AssistantBudget $budget, private AssistantTools $tools) {}

    /** @return array<string, mixed> */
    public function execute(AssistantInteractionContext $context, AssistantInput $input, AssistantExecutionGuard $guard): array
    {
        $context->fresh();
        $guard->check();
        $this->budget->consume($context);

        return $this->executeAdmitted($context, $input, $guard);
    }

    /** @return array<string, mixed> */
    public function executeJson(AssistantInteractionContext $context, #[\SensitiveParameter] string $json, AssistantExecutionGuard $guard): array
    {
        $context->fresh();
        $guard->check();
        $this->budget->consume($context);

        return $this->executeAdmitted($context, AssistantInput::fromJson($json), $guard);
    }

    /** @return array<string, mixed> */
    private function executeAdmitted(AssistantInteractionContext $context, AssistantInput $input, AssistantExecutionGuard $guard): array
    {
        $guard->check();
        $lock = $this->budget->admit($context, $input);
        try {
            $invocation = AssistantProviderInvocation::forInteraction($context, $input, $guard);
            $invocation->verify();
            AssistantAudit::record($context, 'assistant.interaction.started', ['outcome' => 'started']);
            $started = hrtime(true) / 1e9;
            $planJson = $this->planner->plan(AssistantPlan::plannerInput($input, $context->execution->permissions), $invocation);
            $guard->plannerFinished($started);
            $plan = AssistantPlan::fromJson($planJson, $input, $context->execution->permissions);
            $results = [];
            foreach ($plan->calls as $call) {
                $invocation->verify();
                $callId = (string) Str::uuid();
                try {
                    $execution = $context->fresh(AssistantPlan::PERMISSIONS[$call['tool']]);
                    $invocation->verify();
                    AssistantAudit::record($context, 'assistant.tool.requested', ['tool' => $call['tool'], 'tool_call_id' => $callId, 'outcome' => 'requested']);
                    $invocation->verify();
                    $data = $this->tools->read($execution, $call);
                    $invocation->verify();
                    $resultId = (string) Str::uuid();
                    $result = ['result_id' => $resultId, 'tool' => $call['tool'], 'read_at' => now()->utc()->toIso8601String(), 'data' => $data];
                    AssistantAudit::record($context, 'assistant.tool.succeeded', ['tool' => $call['tool'], 'tool_call_id' => $callId, 'result_id' => $resultId, 'outcome' => 'succeeded']);
                    $results[] = $result;
                    abort_if(strlen(json_encode($results, JSON_THROW_ON_ERROR)) > 32768, 503);
                } catch (\Throwable $error) {
                    AssistantAudit::record($context, 'assistant.tool.denied', ['tool' => $call['tool'], 'tool_call_id' => $callId, 'outcome' => AssistantAudit::failureOutcome($error)]);
                    throw $error;
                }
            }
            $context->fresh();
            foreach ($plan->calls as $call) {
                $context->fresh(AssistantPlan::PERMISSIONS[$call['tool']]);
            }
            $invocation->verify();
            $response = ['request_id' => $context->execution->correlationId, 'interaction_id' => $context->interactionId, 'context' => $context->publicContext(),
                'outcome' => match ($plan->decision) {
                    'read' => 'answered', 'clarify' => 'clarification_required', default => 'unsupported'
                }, 'reason' => $plan->reason, 'results' => $results];
            abort_if(strlen(json_encode($response, JSON_THROW_ON_ERROR)) > 49152, 503);
            AssistantAudit::record($context, 'assistant.interaction.completed', ['outcome' => $response['outcome'], 'tool_count' => count($results)]);
            $context->fresh();
            foreach ($plan->calls as $call) {
                $context->fresh(AssistantPlan::PERMISSIONS[$call['tool']]);
            }
            $invocation->verify();

            return $response;
        } finally {
            $lock->release();
        }
    }
}
