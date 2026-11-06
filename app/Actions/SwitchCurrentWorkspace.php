<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;

/**
 * The company this person works in. It is one value on the account, so the web
 * and every phone they are signed in on follow it.
 */
final readonly class SwitchCurrentWorkspace
{
    public function execute(User $user, Workspace $workspace): void
    {
        $previousWorkspaceId = $user->current_workspace_id;

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        activity('workspace')
            ->event('workspace-switched')
            ->causedBy($user)
            ->performedOn($workspace)
            ->withProperties(['previous_workspace_id' => $previousWorkspaceId])
            ->log('workspace switched');
    }
}
