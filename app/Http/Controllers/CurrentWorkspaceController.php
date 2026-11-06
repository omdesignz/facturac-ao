<?php

namespace App\Http\Controllers;

use App\Actions\SwitchCurrentWorkspace;
use App\Http\Requests\SwitchWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class CurrentWorkspaceController extends Controller
{
    public function update(SwitchWorkspaceRequest $request, Workspace $workspace, SwitchCurrentWorkspace $switch): RedirectResponse
    {
        $switch->execute($request->user(), $workspace);

        return redirect()->route('dashboard');
    }
}
