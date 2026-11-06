<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\SwitchCurrentWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Moves the account to another of its companies, as the web's switcher does, and
 * answers with the profile the app rebuilds itself from. The token stays valid:
 * it belongs to the person and the device, not to a company.
 */
class MobileCurrentCompanyController
{
    public function __invoke(
        Request $request,
        SwitchCurrentWorkspace $switch,
        MobileProfileController $profile,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['workspace_public_id' => ['required', 'string', 'max:40']]);
        $workspace = Workspace::query()->where('public_id', $validated['workspace_public_id'])->first();

        // A company this person is not in looks the same as one that does not exist.
        abort_unless($workspace instanceof Workspace && Gate::allows('view', $workspace), 404);

        if ($user->current_workspace_id !== $workspace->id) {
            $switch->execute($user, $workspace);
        }

        $user->unsetRelation('currentWorkspace');

        return $profile($request);
    }
}
