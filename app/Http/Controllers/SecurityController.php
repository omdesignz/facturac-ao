<?php

namespace App\Http\Controllers;

use App\Http\ActiveSessions;
use App\Models\WorkspaceMembership;
use App\NotificationTopic;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(private ActiveSessions $sessions) {}

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
            'passkeys' => $user->passkeys()
                ->latest()
                ->get()
                ->map(fn ($passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ])
                ->all(),
            // Deliberately not "workSession": that name belongs to the shared
            // countdown prop, and shadowing it would blank the running timer.
            'workSessionPreference' => [
                'minutes' => $user->work_session_minutes
                    ?? (int) config('work_session.default_minutes'),
                'min_minutes' => (int) config('work_session.min_minutes'),
                'max_minutes' => (int) config('work_session.max_minutes'),
                'enabled' => (bool) config('work_session.enabled'),
            ],
            'socialConnections' => [
                'google' => $user->socialIdentities()->where('provider', 'google')->exists(),
            ],
            'sessions' => $this->sessions->forUser($user, $request->session()->getId()),
            'notificationTopics' => array_map(
                fn (NotificationTopic $topic): array => [
                    'value' => $topic->value,
                    'label' => $topic->label(),
                    'description' => $topic->description(),
                    'database' => $user->wantsNotification($topic, 'database'),
                    'mail' => $user->wantsNotification($topic, 'mail'),
                ],
                NotificationTopic::configurable(),
            ),
        ]);
    }
}
