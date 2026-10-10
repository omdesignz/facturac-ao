<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final readonly class ExecutionContext implements DocumentReadContext
{
    /** @param list<string> $permissions */
    private function __construct(
        public int $actorId,
        public int $workspaceId,
        public int $legalEntityId,
        public AgtEnvironment $environment,
        public array $permissions,
        public string $correlationId,
        public ?int $approvalId,
        public ?string $automationId,
        public ?int $realActorId,
        public ?string $impersonationSession,
    ) {}

    public static function resolve(User $actor, LegalEntity $entity, AgtEnvironment $environment, ?int $approvalId = null, ?string $automationId = null, bool $readOnly = false): self
    {
        $actor = $actor->newQuery()->useWritePdo()->find($actor->getKey());
        $entity = $entity->newQuery()->useWritePdo()->find($entity->getKey());
        abort_unless($actor instanceof User && $entity instanceof LegalEntity && $actor->hasVerifiedEmail() && $actor->hasEnabledTwoFactorAuthentication(), 403);
        abort_if(! $readOnly && Context::get('impersonator_id') !== null, 403);
        abort_unless($readOnly || $environment->isEnabled(), 403);
        $membership = WorkspaceMembership::query()->useWritePdo()->where('user_id', $actor->id)
            ->where('workspace_id', $entity->workspace_id)->where('is_active', true)->first();
        abort_unless($membership !== null, 403);
        $canWrite = in_array($membership->role, [WorkspaceRole::Owner, WorkspaceRole::Administrator, WorkspaceRole::Accountant, WorkspaceRole::Billing], true);
        abort_unless($readOnly || $canWrite, 403);

        return new self($actor->id, $entity->workspace_id, $entity->id, $environment,
            [...($readOnly ? ['documents.read', 'documents.agt-status.read', 'customers.read', 'catalogue.read'] : ['documents.read', 'documents.agt-status.read', 'customers.read', 'catalogue.read', 'recurring.approve', 'fiscal.issue']),
                ...(in_array($membership->role, [WorkspaceRole::Owner, WorkspaceRole::Administrator, WorkspaceRole::Accountant], true) && $automationId === null ? ['analytics.billing.read'] : [])],
            (string) (Context::get('request_id') ?? Str::uuid()), $approvalId, $automationId,
            Context::get('impersonator_id') === null ? null : (int) Context::get('impersonator_id'),
            Context::get('impersonation_session') === null ? null : (string) Context::get('impersonation_session'));
    }

    public function workspaceId(): int
    {
        return $this->workspaceId;
    }

    public function legalEntityId(): int
    {
        return $this->legalEntityId;
    }

    public function environment(): AgtEnvironment
    {
        return $this->environment;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function auditCauser(): Model
    {
        return User::findOrFail($this->actorId);
    }

    public function recordSuccessfulUse(): void {}

    public function authorize(string $permission): void
    {
        abort_unless(in_array($permission, $this->permissions, true), 403);
        $isRead = in_array($permission, ['analytics.billing.read', 'documents.read', 'documents.agt-status.read', 'customers.read', 'catalogue.read'], true);
        abort_if(! $isRead && $this->realActorId !== null, 403);
        abort_if($permission === 'analytics.billing.read' && ($this->environment !== AgtEnvironment::Production || $this->automationId !== null), 403);
        $fresh = self::resolve(User::query()->useWritePdo()->findOrFail($this->actorId), LegalEntity::query()->useWritePdo()->findOrFail($this->legalEntityId),
            $this->environment, readOnly: $isRead);
        abort_unless($fresh->workspaceId === $this->workspaceId && in_array($permission, $fresh->permissions, true), 403);
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        return ['actor_kind' => $this->automationId === null ? 'human' : 'scheduled',
            'principal_kind' => 'user', 'effective_actor_id' => $this->actorId,
            'real_actor_id' => $this->realActorId ?? $this->actorId, 'impersonation_session' => $this->impersonationSession,
            'actor_id' => $this->actorId, 'authority_user_id' => $this->actorId,
            'workspace_id' => $this->workspaceId, 'legal_entity_id' => $this->legalEntityId,
            'environment' => $this->environment->value, 'request_id' => $this->correlationId,
            'approval_id' => $this->approvalId, 'automation_id' => $this->automationId];
    }
}
