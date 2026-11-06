<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Fiscal\Agt\CompanySetupProgress;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileCompanySetupController
{
    public function __invoke(Request $request, CompanySetupProgress $progress): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        /** @var User $user */
        $user = $request->user();

        if ($request->hasHeader('X-Company') && $request->header('X-Company') !== $workspace->public_id) {
            return response()->json(['error' => [
                'code' => 'COMPANY_CHANGED',
                'message' => 'Esta conta passou para outra empresa. Nada foi guardado.',
            ]], 409)->header('Cache-Control', 'private, no-store');
        }

        $setup = $progress->forWorkspace($workspace, $user);
        $canContinue = $setup['next_step'] === 'security'
            || ($setup['next_step'] !== 'permission' && $user->can('update', $workspace));

        return response()->json(['data' => [
            ...$setup,
            'workspace_public_id' => $workspace->public_id,
            'company_name' => $workspace->name,
            'continue_url' => $canContinue ? route('company-setup.show', $workspace) : null,
            'help' => $canContinue ? null : 'Peça ao administrador da empresa para concluir este passo.',
        ]])->header('Cache-Control', 'private, no-store');
    }
}
