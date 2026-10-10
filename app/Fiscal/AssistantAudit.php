<?php

namespace App\Fiscal;

use App\Exceptions\AiExecutionDisabled;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AssistantAudit
{
    public static function failureOutcome(\Throwable $error): string
    {
        if ($error instanceof AiExecutionDisabled) {
            return 'provider_disabled';
        }

        return match ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503) {
            401, 403, 419 => 'authorization_rejected',
            409 => 'interaction_conflict',
            405, 422 => 'invalid_input',
            429 => 'quota_rejected',
            default => 'unavailable',
        };
    }

    /** @param array<string, mixed> $metadata */
    public static function record(?AssistantInteractionContext $context, string $event, array $metadata = []): void
    {
        $actor = $context === null ? request()->user() : User::query()->useWritePdo()->find($context->execution->actorId);
        $properties = $context === null ? ['actor_kind' => $actor === null ? 'unauthenticated' : 'human',
            'principal_kind' => 'user', 'effective_actor_id' => $actor?->getKey(), 'real_actor_id' => $actor?->getKey(), 'request_id' => Context::get('request_id')] : $context->execution->audit();
        $properties = [...$properties, 'user_agent' => null, 'ip_address' => null, 'schema_version' => 1, 'planner_configuration' => 'bounded-port-v1',
            'interaction_id' => $context?->interactionId, 'actor_attribution_id' => $context === null ? ($actor instanceof User ? $actor->attribution_id : null) : $context->actorAttributionId, ...$metadata];
        RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event($event)->withProperties($properties)->log('Bounded assistant read'));
    }
}
