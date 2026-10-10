<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final readonly class AssistantInteractionContext
{
    private function __construct(public ExecutionContext $execution, public int $membershipId, public int $workDeadline,
        public string $workspacePublicId, public string $entityPublicId, public string $interactionId, public string $actorAttributionId) {}

    public static function resolve(Request $request): self
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_if(Context::get('impersonator_id') !== null || Context::get('automation_id') !== null
            || $request->session()->has((string) config('impersonation.session.impersonator')), 403);
        $actor = User::query()->useWritePdo()->find($actor->id);
        abort_unless($actor instanceof User && $actor->hasVerifiedEmail() && $actor->hasEnabledTwoFactorAuthentication(), 403);
        $workspaceId = AssistantJson::publicId($request->route('workspacePublicId'), 422);
        $entityId = AssistantJson::publicId($request->route('entityPublicId'), 422);
        abort_unless($request->route('environment') === 'production', 403);
        $workspace = Workspace::query()->useWritePdo()->where('public_id', $workspaceId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id)->where('is_active', true))->first();
        abort_unless($workspace instanceof Workspace, 403);
        $entity = LegalEntity::query()->useWritePdo()->where('workspace_id', $workspace->id)->where('public_id', $entityId)->first();
        abort_unless($entity instanceof LegalEntity, 404);
        $membership = WorkspaceMembership::query()->useWritePdo()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->where('is_active', true)->firstOrFail();
        $deadline = PHP_INT_MAX;
        if (config('work_session.enabled')) {
            $started = $request->session()->get((string) config('work_session.started_at_key'));
            abort_unless(is_int($started), 401);
            $minutes = max((int) config('work_session.min_minutes'), min((int) config('work_session.max_minutes'), $actor->work_session_minutes ?? (int) config('work_session.default_minutes')));
            $deadline = $started + $minutes * 60;
        }
        $context = new self(ExecutionContext::resolve($actor, $entity, AgtEnvironment::Production, readOnly: true), $membership->id, $deadline,
            strtolower($workspace->public_id), strtolower($entity->public_id), (string) Str::uuid(), $actor->attribution_id);
        $context->fresh();

        return $context;
    }

    public function fresh(?string $permission = null): ExecutionContext
    {
        abort_if(now()->getTimestamp() >= $this->workDeadline, 401);
        $membership = WorkspaceMembership::query()->useWritePdo()->find($this->membershipId);
        abort_unless($membership instanceof WorkspaceMembership && $membership->is_active
            && $membership->user_id === $this->execution->actorId && $membership->workspace_id === $this->execution->workspaceId, 403);
        $actor = User::query()->useWritePdo()->find($this->execution->actorId);
        $entity = LegalEntity::query()->useWritePdo()->find($this->execution->legalEntityId);
        abort_unless($actor instanceof User && $entity instanceof LegalEntity && $entity->workspace_id === $this->execution->workspaceId, 403);
        $fresh = ExecutionContext::resolve($actor, $entity, AgtEnvironment::Production, readOnly: true);
        abort_if($fresh->realActorId !== null || $fresh->automationId !== null, 403);
        if ($permission !== null) {
            abort_unless(in_array($permission, $this->execution->permissions, true), 403);
            $fresh->authorize($permission);
        }

        return $fresh;
    }

    /** @return array{workspace_public_id: string, legal_entity_public_id: string, environment: string} */
    public function publicContext(): array
    {
        return ['workspace_public_id' => $this->workspacePublicId, 'legal_entity_public_id' => $this->entityPublicId, 'environment' => 'production'];
    }
}
