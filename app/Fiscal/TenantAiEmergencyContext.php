<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/** Owner authority restricted to local disable/revoke; never accepted by custody insertion. */
final readonly class TenantAiEmergencyContext
{
    private function __construct(private Request $request, public int $workspaceId, public string $workspacePublicId,
        public int $actorId, public int $membershipId, public string $deploymentId) {}

    public static function resolve(Request $request, string $workspacePublicId): self
    {
        try {
            $actor = $request->user('web');
            $workspace = Workspace::query()->useWritePdo()->where('public_id', $workspacePublicId)->first();
            $deployment = config('tenant_ai.deployment_id');
            if (! $actor instanceof User || ! $workspace instanceof Workspace || ! is_string($deployment)) {
                throw new TenantAiStorageUnavailable;
            }
            $membership = WorkspaceMembership::query()->useWritePdo()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->first();
            if (! $membership instanceof WorkspaceMembership) {
                throw new TenantAiStorageUnavailable;
            }
            $self = new self($request, $workspace->id, $workspace->public_id, $actor->id, $membership->id, $deployment);
            $self->authorize();

            return $self;
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    public function authorize(): User
    {
        $actor = User::query()->useWritePdo()->find($this->actorId);
        $member = WorkspaceMembership::query()->useWritePdo()->find($this->membershipId);
        $session = $this->request->hasSession() ? $this->request->session() : null;
        $started = $session?->get((string) config('work_session.started_at_key'));
        $minutes = max((int) config('work_session.min_minutes'), min((int) config('work_session.max_minutes'), (int) $actor?->work_session_minutes));
        $csrf = $this->request->header('X-CSRF-TOKEN');
        if (! $actor instanceof User || ! $member instanceof WorkspaceMembership || $member->role !== WorkspaceRole::Owner || ! $member->is_active
            || $member->workspace_id !== $this->workspaceId || $member->user_id !== $this->actorId
            || $this->request->user('web')?->getAuthIdentifier() !== $this->actorId || auth('web')->id() !== $this->actorId
            || ! $actor->hasVerifiedEmail() || ! $actor->hasEnabledTwoFactorAuthentication()
            || ! $this->request->isMethod('POST') || ! is_string($csrf) || ! is_string($session?->token()) || ! hash_equals($session->token(), $csrf)
            || ! is_int($started) || $started > time() || time() >= $started + $minutes * 60
            || Context::get('impersonator_id') !== null || Context::get('automation_id') !== null
            || $session->has((string) config('impersonation.session.impersonator'))
            || config('tenant_ai.deployment_id') !== $this->deploymentId
            || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            throw new TenantAiStorageUnavailable;
        }

        return $actor;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['context' => 'restricted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
