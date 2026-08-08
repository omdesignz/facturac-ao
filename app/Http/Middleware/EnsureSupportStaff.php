<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the support console to the platform's own staff.
 *
 * Answers 404 rather than 403 so the console's existence is not advertised to
 * customers who go looking.
 */
class EnsureSupportStaff
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSupportStaff() || ! config('impersonation.enabled')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
