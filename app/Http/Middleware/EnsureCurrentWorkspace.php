<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentWorkspace
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $membership = $this->currentMembership($user->id, $user->current_workspace_id);

        if ($membership === null) {
            $membership = WorkspaceMembership::query()
                ->with('workspace')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->oldest('id')
                ->first();
        }

        if ($membership === null) {
            return redirect()->route('workspace.setup');
        }

        if ($user->current_workspace_id !== $membership->workspace_id) {
            $user->forceFill(['current_workspace_id' => $membership->workspace_id])->saveQuietly();
        }

        $workspace = $membership->workspace;
        $user->setRelation('currentWorkspace', $workspace);
        $request->attributes->set('currentWorkspace', $workspace);
        $request->attributes->set('currentWorkspaceMembership', $membership);

        Context::add([
            'workspace_id' => $workspace->id,
            'workspace_public_id' => $workspace->public_id,
            'workspace_role' => $membership->role->value,
        ]);

        return $next($request);
    }

    private function currentMembership(int $userId, ?int $workspaceId): ?WorkspaceMembership
    {
        if ($workspaceId === null) {
            return null;
        }

        return WorkspaceMembership::query()
            ->with('workspace')
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->first();
    }
}
