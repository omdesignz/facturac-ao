<?php

namespace App\Policies;

use App\Models\TransportDocument;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

class TransportDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeCurrentMembership($user) !== null;
    }

    public function view(User $user, TransportDocument $transportDocument): bool
    {
        return $this->activeMembership($user, $transportDocument) !== null;
    }

    public function create(User $user): bool
    {
        return $this->canPrepareDocuments($this->activeCurrentMembership($user));
    }

    public function update(User $user, TransportDocument $transportDocument): bool
    {
        return $transportDocument->isMutable()
            && $this->canPrepareDocuments($this->activeMembership($user, $transportDocument));
    }

    public function issue(User $user, TransportDocument $transportDocument): bool
    {
        return $transportDocument->isMutable()
            && $this->canPrepareDocuments($this->activeMembership($user, $transportDocument));
    }

    public function cancel(User $user, TransportDocument $transportDocument): bool
    {
        return $transportDocument->canCancel()
            && $this->canPrepareDocuments($this->activeMembership($user, $transportDocument));
    }

    public function delete(User $user, TransportDocument $transportDocument): bool
    {
        return false;
    }

    public function restore(User $user, TransportDocument $transportDocument): bool
    {
        return false;
    }

    public function forceDelete(User $user, TransportDocument $transportDocument): bool
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

    private function activeMembership(
        User $user,
        TransportDocument $transportDocument,
    ): ?WorkspaceMembership {
        if ($user->current_workspace_id !== $transportDocument->workspace_id) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $transportDocument->workspace_id)
            ->where('is_active', true)
            ->first();
    }
}
