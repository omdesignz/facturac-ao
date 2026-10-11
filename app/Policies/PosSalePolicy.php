<?php

namespace App\Policies;

use App\Models\PosSale;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * Anyone in the company may read a till receipt; sales are made only through
 * the shift's own policy.
 */
class PosSalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_workspace_id !== null
            && $this->isMember($user, $user->current_workspace_id);
    }

    public function view(User $user, PosSale $posSale): bool
    {
        return $user->current_workspace_id === $posSale->workspace_id
            && $this->isMember($user, $posSale->workspace_id);
    }

    private function isMember(User $user, int $workspaceId): bool
    {
        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->exists();
    }
}
