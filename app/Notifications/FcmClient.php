<?php

namespace App\Notifications;

use App\Models\PushDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** HTTP v1 data messages; the Android app checks the sign-in before displaying. */
class FcmClient
{
    public static function isConfigured(): bool
    {
        return is_string(config('services.fcm.project_id'))
            && preg_match('/\A[a-z][a-z0-9-]{4,61}[a-z0-9]\z/', config('services.fcm.project_id')) === 1
            && filled(config('services.fcm.client_email'))
            && filled(config('services.fcm.private_key'));
    }

    /** @param array<string, mixed> $payload */
    public function send(PushDevice $device, array $payload, ?string $collapseId = null): PushDelivery
    {
        if (! self::isConfigured() || $device->binding === null) {
            return PushDelivery::Failed;
        }

        try {
            $data = [
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'event_id' => $collapseId ?? (string) str()->uuid(),
                'binding' => $device->binding,
            ];
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 3800) {
                return PushDelivery::Failed;
            }
            $response = Http::withToken($this->accessToken())
                ->connectTimeout(3)->timeout(10)
                ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                    'message' => [
                        'token' => $device->token,
                        'data' => $data,
                        'android' => ['priority' => 'high', 'ttl' => '86400s'],
                    ],
                ]);
        } catch (Throwable) {
            // Provider exceptions can contain the token or signed JWT URL/body.
            Log::warning('Firebase delivery could not complete.', ['device' => $device->id]);

            return PushDelivery::Failed;
        }

        if ($response->successful()) {
            return PushDelivery::Delivered;
        }
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail)
                && ($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return PushDelivery::DeviceGone;
            }
        }
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }
        Log::warning('Firebase refused a notification.', ['device' => $device->id, 'status' => $response->status()]);

        return PushDelivery::Failed;
    }

    private function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $header = $this->base64Url('{"alg":"RS256","typ":"JWT"}');
        $claims = $this->base64Url(json_encode([
            'iss' => config('services.fcm.client_email'),
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));
        $configured = (string) config('services.fcm.private_key');
        $pem = str_contains($configured, '-----BEGIN')
            ? str_replace('\\n', "\n", $configured)
            : @file_get_contents($configured);
        $key = $pem === false ? false : openssl_pkey_get_private($pem);
        if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Firebase signing key is unavailable.');
        }
        $response = Http::asForm()->connectTimeout(3)->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$this->base64Url($signature),
            ]);
        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($expires) || $expires <= 60) {
            throw new RuntimeException('Firebase authorization is unavailable.');
        }
        Cache::put($this->cacheKey(), $token, min(3000, (int) $expires - 60));

        return $token;
    }

    private function cacheKey(): string
    {
        return 'fcm.access.'.hash('sha256', json_encode(config('services.fcm'), JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
