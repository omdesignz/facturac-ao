<?php

namespace App\Notifications;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification to every phone its recipient is signed in on.
 *
 * A failure is not thrown back to the queue: a retry would send again to the
 * phones that already had it, and a duplicate buzz is worse than a missed one
 * the bell still holds.
 */
class PushChannel
{
    public function __construct(private readonly ApnsClient $apns, private readonly FcmClient $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof WorkspaceNotification) {
            return;
        }

        $payload = $notification->toPush($notifiable);

        foreach ($notifiable->pushDevices()->signedIn()->get() as $device) {
            $client = $device->provider === 'fcm' ? $this->fcm : $this->apns;
            if (! $client::isConfigured()) {
                continue;
            }

            try {
                $devicePayload = $device->binding === null ? $payload : [...$payload, 'binding' => $device->binding];
                if ($client->send($device, $devicePayload, $notification->dedupeKey()) === PushDelivery::DeviceGone) {
                    PushDevice::query()->whereKey($device->id)
                        ->where('updated_at', $device->getRawOriginal('updated_at'))
                        ->where('personal_access_token_id', $device->personal_access_token_id)
                        ->where('token_hash', $device->token_hash)->where('provider', $device->provider)
                        ->where('environment', $device->environment)->where('bundle_id', $device->bundle_id)
                        ->where('binding', $device->binding)
                        ->delete();
                }
            } catch (Throwable) {
                Log::warning('Push delivery failed.', ['device' => $device->id, 'provider' => $device->provider]);
            }
        }
    }
}
