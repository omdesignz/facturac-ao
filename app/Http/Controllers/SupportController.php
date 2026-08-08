<?php

namespace App\Http\Controllers;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support console: find the account behind a reported problem, and read
 * back what was done during previous troubleshooting sessions.
 */
class SupportController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        return Inertia::render('Support/Index', [
            'filters' => ['search' => $search],
            'results' => $search === '' ? [] : $this->search($search),
            'sessions' => $this->recentSessions(),
            'maxMinutes' => (int) config('impersonation.max_minutes'),
            'reasonMinLength' => (int) config('impersonation.reason_min_length'),
        ]);
    }

    /**
     * Searching by an exact-ish identifier rather than listing everyone: support
     * should arrive here from a specific customer's report, not browse the
     * customer base.
     *
     * @return list<array<string, mixed>>
     */
    private function search(string $search): array
    {
        return array_values(User::query()
            ->where('is_support_staff', false)
            ->where(function ($query) use ($search): void {
                $query->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
            ->with('currentWorkspace')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'workspace' => $user->currentWorkspace?->name,
                'email_verified' => $user->email_verified_at !== null,
                'last_impersonated_at' => $user->impersonationsReceived()
                    ->latest('started_at')
                    ->value('started_at')?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentSessions(): array
    {
        return array_values(ImpersonationSession::query()
            ->with(['impersonator', 'subject'])
            ->latest('started_at')
            ->limit(25)
            ->get()
            ->map(fn (ImpersonationSession $session): array => [
                'public_id' => $session->public_id,
                'impersonator' => $session->impersonator->name,
                'subject' => $session->subject->name,
                'subject_email' => $session->subject->email,
                'reason' => $session->reason,
                'ip_address' => $session->ip_address,
                'started_at' => $session->started_at->toIso8601String(),
                'subject_notified' => $session->subject_notified_at !== null,
                'ended_at' => $session->ended_at?->toIso8601String(),
                'ended_by' => $session->ended_by,
                'duration_seconds' => $session->ended_at === null
                    ? null
                    : $session->started_at->diffInSeconds($session->ended_at),
                'writes' => $session->write_count,
                'blocked' => $session->blocked_count,
                'open' => ! $session->hasEnded(),
            ])
            ->all());
    }
}
