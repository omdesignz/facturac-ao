<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkspaceForUser;
use App\Http\Requests\StoreWorkspaceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->workspaceMemberships()->where('is_active', true)->exists()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('WorkspaceSetup');
    }

    public function store(
        StoreWorkspaceRequest $request,
        CreateWorkspaceForUser $createWorkspaceForUser,
    ): RedirectResponse {
        $user = $request->user();

        if ($user->workspaceMemberships()->where('is_active', true)->exists()) {
            return redirect()->route('dashboard');
        }

        $createWorkspaceForUser->execute($user, $request->validated('workspace_name'));

        return redirect()
            ->route('onboarding')
            ->with('success', 'Espaço de trabalho criado. Agora configure a empresa.');
    }
}
