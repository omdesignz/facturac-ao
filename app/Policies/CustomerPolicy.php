<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeCurrentMembership($user) !== null;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->activeMembership($user, $customer->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManageRecords($this->activeCurrentMembership($user));
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->canManageRecords($this->activeMembership($user, $customer->workspace_id));
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->canManageRecords($this->activeMembership($user, $customer->workspace_id));
    }

    public function restore(User $user, Customer $customer): bool
    {
        return false;
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }

    /**
     * Viewers may read the ledger but never change who gets invoiced.
     */
    private function canManageRecords(?WorkspaceMembership $membership): bool
    {
        return $membership !== null && in_array($membership->role, [
            WorkspaceRole::Owner,
            WorkspaceRole::Administrator,
            WorkspaceRole::Accountant,
            WorkspaceRole::Billing,
        ], true);
    }

    private function activeCurrentMembership(User $user): ?WorkspaceMembership
    {
        if ($user->current_workspace_id === null) {
            return null;
        }

        return $this->membership($user, $user->current_workspace_id);
    }

    private function activeMembership(User $user, int $workspaceId): ?WorkspaceMembership
    {
        if ($user->current_workspace_id !== $workspaceId) {
            return null;
        }

        return $this->membership($user, $workspaceId);
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
