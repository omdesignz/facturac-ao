<?php

namespace App\Http\Middleware;

use App\Actions\StopImpersonation;
use App\Models\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Polices a live impersonation on every request.
 *
 * Three jobs: end sessions that have run past their limit, refuse the handful
 * of actions support must never take as the customer, and record the ones they
 * do take. The recording is the point of the feature — a troubleshooting
 * session that leaves no trace is indistinguishable from an intrusion.
 */
class HandleImpersonation
{
    public function __construct(private readonly StopImpersonation $stopImpersonation) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->currentSession($request);

        if (! $session instanceof ImpersonationSession) {
            return $next($request);
        }

        if ($session->remainingSeconds() <= 0) {
            $this->stopImpersonation->execute($request, 'expired');

            return redirect()
                ->route('support.index')
                ->with('status', 'A sessão de diagnóstico chegou ao limite de tempo e foi encerrada.');
        }

        // Tags anything else logged during this request as impersonated, so the
        // regular audit trail cannot be mistaken for the customer's own doing.
        Context::add([
            'impersonation_session' => $session->public_id,
            'impersonator_id' => $session->impersonator_id,
        ]);

        if ($this->isBlocked($request)) {
            $session->increment('blocked_count');

            $this->log($session, 'blocked', $request, 'refused an action not allowed while impersonating');

            return $this->refuse($request);
        }

        $response = $next($request);

        if ($this->isWrite($request) && $this->succeeded($response)) {
            $session->increment('write_count');

            $this->log($session, 'write', $request, 'changed data while impersonating');
        }

        return $response;
    }

    private function currentSession(Request $request): ?ImpersonationSession
    {
        $recordId = $request->session()->get((string) config('impersonation.session.record'));

        if (! is_int($recordId)) {
            return null;
        }

        $session = ImpersonationSession::query()->find($recordId);

        return $session instanceof ImpersonationSession && ! $session->hasEnded()
            ? $session
            : null;
    }

    private function isBlocked(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! is_string($name)) {
            return false;
        }

        /** @var array<int, string> $blocked */
        $blocked = config('impersonation.blocked_routes', []);

        return in_array($name, $blocked, true);
    }

    /**
     * Leaving counts as nothing: the request that ends the session is support
     * stepping out, not a change to the customer's data, and recording it as
     * one overstates every session by exactly one write.
     */
    private function isWrite(Request $request): bool
    {
        if ($request->route()?->getName() === 'support.impersonation.destroy') {
            return false;
        }

        return ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * A rejected write changed nothing, so counting it would overstate what
     * support actually did to the account.
     */
    private function succeeded(Response $response): bool
    {
        return $response->getStatusCode() < 400;
    }

    private function log(
        ImpersonationSession $session,
        string $event,
        Request $request,
        string $description,
    ): void {
        activity('impersonation')
            ->event($event)
            ->causedBy($session->impersonator)
            ->performedOn($session->subject)
            ->withProperties([
                'impersonation_session' => $session->public_id,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
            ])
            ->log($description);
    }

    private function refuse(Request $request): Response
    {
        $message = 'Esta operação não pode ser feita durante uma sessão de diagnóstico. Peça ao cliente para a realizar.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return back()->with('error', $message);
    }
}
