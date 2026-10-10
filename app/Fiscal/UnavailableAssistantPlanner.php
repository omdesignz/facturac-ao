<?php

namespace App\Fiscal;

use App\Exceptions\AiExecutionDisabled;

final class UnavailableAssistantPlanner implements AssistantPlanner
{
    public function plan(#[\SensitiveParameter] array $input, ?AssistantProviderInvocation $invocation = null): string
    {
        if ($invocation !== null) {
            AssistantAudit::record($invocation->context, 'assistant.provider.blocked',
                ['provider_profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY, 'outcome' => 'provider_disabled']);
        }
        throw new AiExecutionDisabled;
    }
}
