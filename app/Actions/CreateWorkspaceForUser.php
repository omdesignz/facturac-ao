<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkspaceForUser
{
    public function execute(User $user, string $workspaceName): Workspace
    {
        return DB::transaction(function () use ($user, $workspaceName): Workspace {
            $workspace = Workspace::query()->create([
                'name' => trim($workspaceName),
                'slug' => $this->uniqueSlug($workspaceName),
                'created_by_user_id' => $user->id,
            ]);

            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => WorkspaceRole::Owner,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            $user->setRelation('currentWorkspace', $workspace);

            return $workspace;
        });
    }

    private function uniqueSlug(string $workspaceName): string
    {
        $baseSlug = Str::slug($workspaceName) ?: 'workspace';

        do {
            $slug = $baseSlug.'-'.Str::lower(Str::random(8));
        } while (Workspace::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
