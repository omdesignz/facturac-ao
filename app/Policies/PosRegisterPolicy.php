<?php

namespace App\Policies;

use App\Models\PosRegister;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * Registers are set up by whoever runs the company; everyone in it can see them.
 */
class PosRegisterPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->currentMembership($user) !== null;
    }

    public function view(User $user, PosRegister $posRegister): bool
    {
        return $this->membershipIn($user, $posRegister->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return $this->currentMembership($user)?->role->canManageWorkspace() === true;
    }

    public function update(User $user, PosRegister $posRegister): bool
    {
        return $this->membershipIn($user, $posRegister->workspace_id)?->role->canManageWorkspace() === true;
    }

    public function delete(User $user, PosRegister $posRegister): bool
    {
        return $this->update($user, $posRegister);
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
