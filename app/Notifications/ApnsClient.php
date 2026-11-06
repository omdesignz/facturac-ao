<?php

namespace App\Notifications;

use App\Models\PushDevice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hands one notification to Apple for one phone.
 *
 * Apple's HTTP/2 interface, signed with a token rather than a certificate, so
 * nothing expires every year and no package is needed for it. The token is
 * reused for fifty minutes: Apple refuses one older than an hour and also
 * throttles a sender that makes a new one for every message.
 */
class ApnsClient
{
    /**
     * Apple's answers that mean the phone will never take this token again:
     * the app was deleted, or the token belongs to another app or environment.
     */
    private const GONE_REASONS = ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'];

    public static function isConfigured(): bool
    {
        return filled(config('services.apns.key_id'))
            && filled(config('services.apns.team_id'))
            && filled(config('services.apns.private_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushDevice $device, array $payload, ?string $collapseId = null): PushDelivery
    {
        $headers = [
            'apns-topic' => (string) config('services.apns.bundle_id'),
            'apns-push-type' => 'alert',
            'apns-priority' => '10',
            // News that took a day to reach a phone that was off is stale.
            'apns-expiration' => (string) now()->addDay()->getTimestamp(),
        ];

        // A second notice about the same thing replaces the first on the lock
        // screen rather than stacking under it.
        if ($collapseId !== null && strlen($collapseId) <= 64) {
            $headers['apns-collapse-id'] = $collapseId;
        }

        return $this->deliver($device->token, $device->environment, $headers, $payload, ['device' => $device->id]);
    }

    /**
     * Moves a Live Activity on: Apple redraws it on the lock screen from the
     * new state, whether or not the app is running.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendLiveActivity(string $token, string $environment, array $payload): PushDelivery
    {
        return $this->deliver($token, $environment, [
            'apns-topic' => config('services.apns.bundle_id').'.push-type.liveactivity',
            'apns-push-type' => 'liveactivity',
            'apns-priority' => '10',
        ], $payload, ['live_activity' => true]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function deliver(string $token, string $environment, array $headers, array $payload, array $context): PushDelivery
    {
        $host = $environment === 'sandbox' ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';

        try {
            $response = Http::withOptions(['version' => 2.0])
                ->withToken($this->providerToken())
                ->withHeaders($headers)
                ->timeout(10)
                ->post("https://{$host}/3/device/{$token}", $payload);
        } catch (ConnectionException $exception) {
            Log::warning('APNs could not be reached.', [...$context, 'error' => $exception->getMessage()]);

            return PushDelivery::Failed;
        }

        if ($response->successful()) {
            return PushDelivery::Delivered;
        }

        $reason = (string) $response->json('reason', '');

        if ($response->status() === 410 || in_array($reason, self::GONE_REASONS, true)) {
            return PushDelivery::DeviceGone;
        }

        if (in_array($reason, ['ExpiredProviderToken', 'InvalidProviderToken'], true)) {
            Cache::forget($this->cacheKey());
        }

        Log::warning('APNs refused a notification.', [...$context, 'status' => $response->status(), 'reason' => $reason]);

        return PushDelivery::Failed;
    }

    private function providerToken(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function (): string {
            $header = $this->base64Url((string) json_encode(['alg' => 'ES256', 'kid' => config('services.apns.key_id')]));
            $claims = $this->base64Url((string) json_encode(['iss' => config('services.apns.team_id'), 'iat' => now()->getTimestamp()]));

            $key = openssl_pkey_get_private($this->privateKey());

            if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The APNs key could not sign a token.');
            }

            return "{$header}.{$claims}.".$this->base64Url($this->rawSignature($signature));
        });
    }

    private function privateKey(): string
    {
        $configured = (string) config('services.apns.private_key');

        if (str_contains($configured, '-----BEGIN')) {
            return str_replace('\n', "\n", $configured);
        }

        $contents = @file_get_contents($configured);

        if ($contents === false) {
            throw new RuntimeException('The APNs key file could not be read.');
        }

        return $contents;
    }

    /**
     * OpenSSL signs in DER, a sequence of two integers; a JSON web token wants
     * the two numbers side by side, 32 bytes each.
     */
    private function rawSignature(string $der): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }

        $raw = '';

        foreach ([0, 1] as $ignored) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            $raw .= str_pad(ltrim($integer, "\x00"), 32, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function cacheKey(): string
    {
        return 'apns:provider-token:'.config('services.apns.key_id');
    }
}
