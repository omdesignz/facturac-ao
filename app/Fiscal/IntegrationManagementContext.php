<?php

namespace App\Fiscal;

use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

final readonly class IntegrationManagementContext
{
    private function __construct(private Request $request, public int $workspaceId, public int $actorId) {}

    public static function resolve(Request $request, int $workspaceId): self
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 403);
        $context = new self($request, $workspaceId, $actor->id);
        $context->authorize(reveal: false);

        return $context;
    }

    public function authorize(bool $reveal = true): User
    {
        $actor = User::query()->useWritePdo()->find($this->actorId);
        abort_unless($actor instanceof User && $this->request->user('web')?->getAuthIdentifier() === $this->actorId
            && $actor->hasVerifiedEmail() && $actor->hasEnabledTwoFactorAuthentication(), 403);
        abort_if(Context::get('impersonator_id') !== null || $this->request->session()->has((string) config('impersonation.session.impersonator')), 403);
        $membership = WorkspaceMembership::query()->useWritePdo()->where('workspace_id', $this->workspaceId)->where('user_id', $actor->id)->where('is_active', true)->first();
        abort_unless($membership && in_array($membership->role, [WorkspaceRole::Owner, WorkspaceRole::Administrator], true), 403);
        $started = $this->request->session()->get((string) config('work_session.started_at_key'));
        $minutes = max((int) config('work_session.min_minutes'), min($actor->work_session_minutes, (int) config('work_session.max_minutes')));
        abort_unless(is_int($started) && $started <= time() && time() < $started + $minutes * 60, 403);
        if ($reveal) {
            $confirmed = $this->request->session()->get('auth.password_confirmed_at');
            abort_unless(is_int($confirmed) && $confirmed <= time() && time() - $confirmed <= 300, 403);
        }

        return $actor;
    }
}
