<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Notifications\AccountAccessedBySupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Steps a support user into a customer's account.
 *
 * The impersonator's own identity is kept in the session so it can be handed
 * back afterwards, and so every page can show whose account is on screen.
 */
class StartImpersonation
{
    public function execute(
        Request $request,
        User $impersonator,
        User $subject,
        string $reason,
    ): ImpersonationSession {
        $this->guard($request, $impersonator, $subject);

        $session = DB::transaction(fn (): ImpersonationSession => ImpersonationSession::query()->create([
            'impersonator_id' => $impersonator->id,
            'subject_id' => $subject->id,
            'workspace_id' => $subject->current_workspace_id,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'started_at' => now(),
        ]));

        // Told at the moment it happens, not afterwards, so an access they did
        // not ask for can be challenged while it is still running.
        $subject->notify(AccountAccessedBySupport::fromModel($session));

        activity('impersonation')
            ->event('started')
            ->causedBy($impersonator)
            ->performedOn($subject)
            ->withProperties([
                'impersonation_session' => $session->public_id,
                'workspace_id' => $subject->current_workspace_id,
                'reason' => $reason,
                'ip_address' => $request->ip(),
            ])
            ->log("started impersonating {$subject->email}");

        $keys = $this->sessionKeys();

        // Held back so returning to their own account does not hand the support
        // user a fresh work block as a side effect of troubleshooting.
        $ownWorkSessionStartedAt = $request->session()->get(
            (string) config('work_session.started_at_key'),
        );

        Auth::guard('web')->login($subject);
        $request->session()->regenerate();

        // Regenerating keeps the session's data, so the confirmation support
        // just gave for their *own* password would otherwise carry over and
        // unlock the customer's password-guarded actions.
        $request->session()->forget('auth.password_confirmed_at');

        $request->session()->put([
            $keys['impersonator'] => $impersonator->id,
            $keys['record'] => $session->id,
            $keys['work_session_started_at'] => $ownWorkSessionStartedAt,
        ]);

        return $session;
    }

    private function guard(Request $request, User $impersonator, User $subject): void
    {
        if (! config('impersonation.enabled')) {
            throw BillingActionRefused::because('Impersonation is disabled.');
        }

        if (! $impersonator->isSupportStaff()) {
            throw BillingActionRefused::because('Only support staff may impersonate.');
        }

        if ($request->session()->has((string) config('impersonation.session.impersonator'))) {
            throw BillingActionRefused::because('An impersonation is already in progress.');
        }

        if ($impersonator->is($subject)) {
            throw BillingActionRefused::because('A user cannot impersonate themselves.');
        }

        // Otherwise one support account is a route to another's privileges, and
        // the audit trail stops naming who actually did the work.
        if ($subject->isSupportStaff()) {
            throw BillingActionRefused::because('Support staff cannot impersonate one another.');
        }
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
