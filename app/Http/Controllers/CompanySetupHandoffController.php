<?php

namespace App\Http\Controllers;

use App\Actions\SwitchCurrentWorkspace;
use App\Fiscal\Agt\CompanySetupProgress;
use App\Http\Requests\SwitchWorkspaceRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanySetupHandoffController extends Controller
{
    public function show(Request $request, Workspace $workspace, CompanySetupProgress $progress): Response|RedirectResponse
    {
        Gate::authorize('view', $workspace);
        /** @var User $user */
        $user = $request->user();

        if ($user->current_workspace_id === $workspace->id) {
            return redirect()->to($progress->destination($progress->forWorkspace($workspace, $user)['next_step']));
        }

        return Inertia::render('CompanySetupHandoff', [
            'company' => ['public_id' => $workspace->public_id, 'name' => $workspace->name],
        ]);
    }

    public function update(SwitchWorkspaceRequest $request, Workspace $workspace, SwitchCurrentWorkspace $switch): RedirectResponse
    {
        $switch->execute($request->user(), $workspace);

        return redirect()->route('company-setup.show', $workspace);
    }
}
