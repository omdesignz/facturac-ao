<?php

namespace App\Policies;

use App\Models\FiscalDocument;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

class FiscalDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeCurrentMembership($user) !== null;
    }

    public function view(User $user, FiscalDocument $fiscalDocument): bool
    {
        return $this->activeMembership($user, $fiscalDocument) !== null;
    }

    public function create(User $user): bool
    {
        return $this->canPrepareDocuments($this->activeCurrentMembership($user));
    }

    public function update(User $user, FiscalDocument $fiscalDocument): bool
    {
        return $fiscalDocument->isMutable()
            && $this->canPrepareDocuments($this->activeMembership($user, $fiscalDocument));
    }

    public function issue(User $user, FiscalDocument $fiscalDocument): bool
    {
        return $fiscalDocument->isMutable()
            && $this->canPrepareDocuments($this->activeMembership($user, $fiscalDocument));
    }

    public function delete(User $user, FiscalDocument $fiscalDocument): bool
    {
        return false;
    }

    public function restore(User $user, FiscalDocument $fiscalDocument): bool
    {
        return false;
    }

    public function forceDelete(User $user, FiscalDocument $fiscalDocument): bool
    {
        return false;
    }

    private function canPrepareDocuments(?WorkspaceMembership $membership): bool
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

    private function activeMembership(User $user, FiscalDocument $fiscalDocument): ?WorkspaceMembership
    {
        if ($user->current_workspace_id !== $fiscalDocument->workspace_id) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $fiscalDocument->workspace_id)
            ->where('is_active', true)
            ->first();
    }
}
