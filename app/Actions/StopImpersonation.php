<?php

namespace App\Actions;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Hands the session back to the support user and closes the audit record.
 *
 * Called both when support stops deliberately and when the server ends a
 * session that has run past its limit, which is what `$endedBy` distinguishes.
 */
class StopImpersonation
{
    public function execute(Request $request, string $endedBy = 'support'): ?User
    {
        $keys = $this->sessionKeys();
        $impersonatorId = $request->session()->get($keys['impersonator']);

        if (! is_int($impersonatorId)) {
            return null;
        }

        $session = $this->closeRecord($request, $endedBy);
        $impersonator = User::query()->find($impersonatorId);

        $ownWorkSessionStartedAt = $request->session()->get($keys['work_session_started_at']);

        $request->session()->forget([
            $keys['impersonator'],
            $keys['record'],
            $keys['work_session_started_at'],
        ]);

        if (! $impersonator instanceof User) {
            // The support account vanished mid-session; there is nobody to hand
            // control back to, so end up signed out rather than left as the
            // customer.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($impersonator);
        $request->session()->regenerate();
        $request->session()->forget('auth.password_confirmed_at');

        // Put back the work block they were on before troubleshooting, which
        // logging in as themselves again would otherwise have restarted.
        if (is_int($ownWorkSessionStartedAt)) {
            $request->session()->put(
                (string) config('work_session.started_at_key'),
                $ownWorkSessionStartedAt,
            );
        }

        if ($session instanceof ImpersonationSession) {
            activity('impersonation')
                ->event('stopped')
                ->causedBy($impersonator)
                ->performedOn($session->subject)
                ->withProperties([
                    'impersonation_session' => $session->public_id,
                    'ended_by' => $endedBy,
                    'duration_seconds' => $session->started_at->diffInSeconds($session->ended_at ?? now()),
                    'writes' => $session->write_count,
                    'blocked' => $session->blocked_count,
                ])
                ->log("stopped impersonating {$session->subject->email}");
        }

        return $impersonator;
    }

    private function closeRecord(Request $request, string $endedBy): ?ImpersonationSession
    {
        $recordId = $request->session()->get($this->sessionKeys()['record']);

        if (! is_int($recordId)) {
            return null;
        }

        $session = ImpersonationSession::query()->find($recordId);

        if (! $session instanceof ImpersonationSession || $session->hasEnded()) {
            return $session;
        }

        $session->forceFill([
            'ended_at' => now(),
            'ended_by' => $endedBy,
        ])->save();

        return $session;
    }

    /**
     * @return array{impersonator: string, record: string, work_session_started_at: string}
     */
    private function sessionKeys(): array
    {
        /** @var array{impersonator: string, record: string, work_session_started_at: string} $keys */
        $keys = config('impersonation.session');

        return $keys;
    }
}
