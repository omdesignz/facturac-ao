<?php

namespace App\Policies;

use App\LegalEntityStatus;
use App\Models\Establishment;
use App\Models\User;
use App\Models\WorkspaceMembership;

class EstablishmentPolicy
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
    public function view(User $user, Establishment $establishment): bool
    {
        return $this->activeMembership($user, $establishment) !== null;
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
    public function update(User $user, Establishment $establishment): bool
    {
        $membership = $this->activeMembership($user, $establishment);

        return $membership?->role->canManageWorkspace() === true
            && in_array($establishment->legalEntity->status, [
                LegalEntityStatus::Draft,
                LegalEntityStatus::Configured,
            ], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Establishment $establishment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Establishment $establishment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Establishment $establishment): bool
    {
        return false;
    }

    private function activeMembership(User $user, Establishment $establishment): ?WorkspaceMembership
    {
        if ($user->current_workspace_id !== $establishment->workspace_id) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $establishment->workspace_id)
            ->where('is_active', true)
            ->first();
    }
}
