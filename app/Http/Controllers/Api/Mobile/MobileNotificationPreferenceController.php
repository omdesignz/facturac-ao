<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobileNotificationPreferencesRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Notifications\ApnsClient;
use App\Notifications\FcmClient;
use App\NotificationTopic;
use App\WorkspaceRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which news makes the phone buzz.
 *
 * One choice per topic for all of the user's phones, beside the bell and email
 * choices the web settings page keeps, which this never touches.
 */
class MobileNotificationPreferenceController
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($user, $request);
    }

    public function update(UpdateMobileNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preferences = $user->notification_preferences ?? [];

        foreach ($request->pushChoices() as $topic => $wanted) {
            $preferences[$topic]['push'] = $wanted;
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        return $this->respond($user, $request);
    }

    private function respond(User $user, Request $request): JsonResponse
    {
        // A topic only some roles receive is offered only to someone who holds
        // one of them in at least one company.
        $roles = $user->workspaceMemberships()->where('is_active', true)->get()
            ->map(fn (WorkspaceMembership $membership): WorkspaceRole => $membership->role);
        $topics = array_values(array_filter(
            NotificationTopic::configurable(),
            fn (NotificationTopic $topic): bool => $roles->contains(fn (WorkspaceRole $role): bool => $topic->isFor($role)),
        ));

        return response()->json(['data' => [
            'available' => $request->query('provider', 'apns') === 'fcm' ? FcmClient::isConfigured() : ApnsClient::isConfigured(),
            'topics' => array_map(fn (NotificationTopic $topic): array => [
                'key' => $topic->value,
                'label' => $topic->label(),
                'description' => $topic->description(),
                'push' => $user->wantsNotification($topic, 'push'),
            ], $topics),
        ]]);
    }
}
