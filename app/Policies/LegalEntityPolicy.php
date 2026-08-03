<?php

namespace App\Policies;

use App\LegalEntityStatus;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;

class LegalEntityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->workspaceMemberships()->where('is_active', true)->exists();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LegalEntity $legalEntity): bool
    {
        return $this->activeMembership($user, $legalEntity) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $user->current_workspace_id)
            ->where('is_active', true)
            ->get()
            ->contains(fn (WorkspaceMembership $membership): bool => $membership->role->canManageWorkspace());
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LegalEntity $legalEntity): bool
    {
        $membership = $this->activeMembership($user, $legalEntity);

        return $membership?->role->canManageWorkspace() === true
            && in_array($legalEntity->status, [
                LegalEntityStatus::Draft,
                LegalEntityStatus::Configured,
            ], true);
    }

    public function manageAgtConnection(User $user, LegalEntity $legalEntity): bool
    {
        $membership = $this->activeMembership($user, $legalEntity);

        return $membership?->role->canManageWorkspace() === true
            && in_array($legalEntity->status, [
                LegalEntityStatus::Configured,
                LegalEntityStatus::Homologation,
                LegalEntityStatus::Active,
            ], true);
    }

    public function testAgtConnection(User $user, LegalEntity $legalEntity): bool
    {
        return $this->manageAgtConnection($user, $legalEntity);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LegalEntity $legalEntity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, LegalEntity $legalEntity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, LegalEntity $legalEntity): bool
    {
        return false;
    }

    private function activeMembership(User $user, LegalEntity $legalEntity): ?WorkspaceMembership
    {
        if ($user->current_workspace_id !== $legalEntity->workspace_id) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('is_active', true)
            ->first();
    }
}
