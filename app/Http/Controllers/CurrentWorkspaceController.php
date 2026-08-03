<?php

namespace App\Http\Controllers;

use App\Http\Requests\SwitchWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class CurrentWorkspaceController extends Controller
{
    public function update(SwitchWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();
        $previousWorkspaceId = $user->current_workspace_id;

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        activity('workspace')
            ->event('workspace-switched')
            ->causedBy($user)
            ->performedOn($workspace)
            ->withProperties(['previous_workspace_id' => $previousWorkspaceId])
            ->log('workspace switched');

        return redirect()->route('dashboard');
    }
}
