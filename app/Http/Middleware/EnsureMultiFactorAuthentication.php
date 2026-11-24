<?php

namespace App\Http\Middleware;

use App\Fiscal\IssuingVerification;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMultiFactorAuthentication
{
    /**
     * Handle an incoming request.
     *
     * With the `issuing` scope ("mfa:issuing") the company's own choice
     * applies: its owner may waive two-step verification for issuing.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $user = $request->user();

        if ($user !== null && $scope === 'issuing') {
            $workspace = $request->attributes->get('currentWorkspace');

            if ($workspace instanceof Workspace && IssuingVerification::satisfiedBy($user, $workspace)) {
                return $next($request);
            }
        }

        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()
                ->route('settings.security')
                ->with('error', 'Active a autenticação multifactor antes de confirmar esta operação sensível.');
        }

        return $next($request);
    }
}
