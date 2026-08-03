<?php

namespace App\Policies;

use App\DataImportStatus;
use App\Models\DataImport;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

class DataImportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->activeCurrentMembership($user) !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DataImport $dataImport): bool
    {
        return $this->activeMembership($user, $dataImport) !== null;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canImport($this->activeCurrentMembership($user));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DataImport $dataImport): bool
    {
        return $this->canImport($this->activeMembership($user, $dataImport))
            && in_array($dataImport->status, [
                DataImportStatus::AwaitingMapping,
                DataImportStatus::Ready,
                DataImportStatus::HasErrors,
            ], true);
    }

    public function commit(User $user, DataImport $dataImport): bool
    {
        return $this->canImport($this->activeMembership($user, $dataImport))
            && in_array($dataImport->status, [
                DataImportStatus::Ready,
                DataImportStatus::Completed,
            ], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DataImport $dataImport): bool
    {
        return $this->canImport($this->activeMembership($user, $dataImport))
            && in_array($dataImport->status, [
                DataImportStatus::AwaitingMapping,
                DataImportStatus::Ready,
                DataImportStatus::HasErrors,
                DataImportStatus::Failed,
            ], true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DataImport $dataImport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DataImport $dataImport): bool
    {
        return false;
    }

    private function canImport(?WorkspaceMembership $membership): bool
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

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $user->current_workspace_id)
            ->where('is_active', true)
            ->first();
    }

    private function activeMembership(User $user, DataImport $dataImport): ?WorkspaceMembership
    {
        if ($user->current_workspace_id !== $dataImport->workspace_id) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $dataImport->workspace_id)
            ->where('is_active', true)
            ->first();
    }
}
