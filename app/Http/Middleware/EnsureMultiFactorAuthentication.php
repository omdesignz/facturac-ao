<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMultiFactorAuthentication
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()
                ->route('settings.security')
                ->with('error', 'Active a autenticação multifactor antes de confirmar esta operação sensível.');
        }

        return $next($request);
    }
}
