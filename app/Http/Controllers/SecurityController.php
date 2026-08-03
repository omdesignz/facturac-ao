<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        /** @var WorkspaceMembership $membership */
        $membership = $request->attributes->get('currentWorkspaceMembership');

        return Inertia::render('Settings/Security', [
            'twoFactor' => [
                'enabled' => $user->two_factor_secret !== null,
                'confirmed' => $user->hasEnabledTwoFactorAuthentication(),
                'required_for_role' => $membership->role->requiresMultiFactorAuthentication(),
            ],
            'socialConnections' => [
                'google' => $user->socialIdentities()->where('provider', 'google')->exists(),
            ],
        ]);
    }
}
