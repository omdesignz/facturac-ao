<?php

namespace App\Policies;

use App\Models\PosSession;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

/**
 * A shift belongs to the person who opened it.
 *
 * Only they sell on it. They, or whoever manages the company, may close it or
 * move cash in and out; a manager can also read every shift while anyone else
 * reads only their own.
 */
class PosSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->currentMembership($user) !== null;
    }

    public function view(User $user, PosSession $posSession): bool
    {
        $membership = $this->membershipIn($user, $posSession->workspace_id);

        return $membership !== null
            && ($posSession->opened_by_user_id === $user->id || $membership->role->canManageWorkspace());
    }

    /** Opening a shift is the standing authority to sell, so it needs the same role as selling. */
    public function create(User $user): bool
    {
        return $this->canSell($this->currentMembership($user));
    }

    public function sell(User $user, PosSession $posSession): bool
    {
        return $posSession->opened_by_user_id === $user->id
            && $this->canSell($this->membershipIn($user, $posSession->workspace_id));
    }

    public function close(User $user, PosSession $posSession): bool
    {
        return $this->view($user, $posSession);
    }

    public function recordCashMovement(User $user, PosSession $posSession): bool
    {
        return $this->view($user, $posSession);
    }

    private function canSell(?WorkspaceMembership $membership): bool
    {
        return $membership !== null && in_array($membership->role, [
            WorkspaceRole::Owner,
            WorkspaceRole::Administrator,
            WorkspaceRole::Accountant,
            WorkspaceRole::Billing,
        ], true);
    }

    private function currentMembership(User $user): ?WorkspaceMembership
    {
        return $user->current_workspace_id === null
            ? null
            : $this->membership($user, $user->current_workspace_id);
    }

    private function membershipIn(User $user, int $workspaceId): ?WorkspaceMembership
    {
        return $user->current_workspace_id === $workspaceId
            ? $this->membership($user, $workspaceId)
            : null;
    }

    private function membership(User $user, int $workspaceId): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->first();
    }
}
