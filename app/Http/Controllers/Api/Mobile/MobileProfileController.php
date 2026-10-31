<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'name' => $user->name,
            'email' => $user->email,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'current_workspace_public_id' => $user->currentWorkspace?->public_id,
            'workspaces' => $user->workspaces()->wherePivot('is_active', true)->orderBy('name')->get()
                ->map(fn (Workspace $workspace): array => [
                    'public_id' => $workspace->public_id,
                    'name' => $workspace->name,
                ])
                ->all(),
        ]]);
    }
}
