<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification to every phone its recipient is signed in on.
 *
 * A failure is not thrown back to the queue: a retry would send again to the
 * phones that already had it, and a duplicate buzz is worse than a missed one
 * the bell still holds.
 */
class PushChannel
{
    public function __construct(private readonly ApnsClient $apns) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof WorkspaceNotification || ! ApnsClient::isConfigured()) {
            return;
        }

        $payload = $notification->toPush($notifiable);

        foreach ($notifiable->pushDevices()->signedIn()->get() as $device) {
            if ($this->apns->send($device, $payload, $notification->dedupeKey()) === PushDelivery::DeviceGone) {
                $device->delete();
            }
        }
    }
}
