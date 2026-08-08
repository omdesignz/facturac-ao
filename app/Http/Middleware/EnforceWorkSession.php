<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session once the user's work block has run out.
 *
 * The deadline lives in the server session, so refreshing the page, opening a
 * new tab, or restarting the browser cannot extend it — only signing in again
 * or explicitly starting a new block does.
 */
class EnforceWorkSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('work_session.enabled') || $user === null) {
            return $next($request);
        }

        // An impersonation runs on its own, shorter clock. Letting this one also
        // apply would sign support out mid-session and leave the troubleshooting
        // record open with no way to close it.
        if ($request->session()->has((string) config('impersonation.session.impersonator'))) {
            return $next($request);
        }

        $key = (string) config('work_session.started_at_key');
        $startedAt = $request->session()->get($key);

        // Sessions that predate this feature adopt the current moment rather
        // than being treated as infinitely old and signed out at once.
        if (! is_int($startedAt)) {
            $request->session()->put($key, now()->getTimestamp());

            return $next($request);
        }

        if (now()->getTimestamp() < $startedAt + $this->windowSeconds($user->work_session_minutes ?? (int) config('work_session.default_minutes'))) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(
                ['message' => 'A sua sessão de trabalho terminou.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return redirect()
            ->route('login')
            ->with('status', 'A sua sessão de trabalho terminou. Entre outra vez para continuar.');
    }

    private function windowSeconds(int $minutes): int
    {
        $bounded = max(
            (int) config('work_session.min_minutes'),
            min($minutes, (int) config('work_session.max_minutes')),
        );

        return $bounded * 60;
    }
}
