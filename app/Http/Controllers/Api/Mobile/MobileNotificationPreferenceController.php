<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobileNotificationPreferencesRequest;
use App\Models\User;
use App\Notifications\ApnsClient;
use App\NotificationTopic;
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

        return $this->respond($user);
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

        return $this->respond($user);
    }

    private function respond(User $user): JsonResponse
    {
        return response()->json(['data' => [
            // Without Apple's key nothing is sent, and the app says so rather
            // than offering switches that do nothing.
            'available' => ApnsClient::isConfigured(),
            'topics' => array_map(fn (NotificationTopic $topic): array => [
                'key' => $topic->value,
                'label' => $topic->label(),
                'description' => $topic->description(),
                'push' => $user->wantsNotification($topic, 'push'),
            ], NotificationTopic::configurable()),
        ]]);
    }
}
