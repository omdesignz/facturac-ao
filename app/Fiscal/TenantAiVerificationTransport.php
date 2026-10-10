<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/** Fixed metadata transport; only the lowest wire method is replaceable in dev-only tests. */
class TenantAiVerificationTransport
{
    use ResolvesAnthropicAddress;

    final public function exchange(TenantAiCredentialVerifier $session, VerificationRequestPermit $permit,
        TenantAiVerificationContext $context, TenantAiVerificationPolicy $policy, #[\SensitiveParameter] string $secret): CredentialVerificationEvidence
    {
        $session->requirePhase($context, 'sending');
        if (DB::transactionLevel() !== 0 || preg_match('/\A[\x20-\x7e]{1,512}\z/', $secret) !== 1) {
            throw new TenantAiStorageUnavailable;
        }
        $deadline = min($context->deadline, hrtime(true) / 1e9 + 8);
        $buffer = new TenantAiVerificationResponseBuffer($deadline);
        try {
            $this->wire($policy->profile->model_key, $policy->account['workspace_id'], $secret, $deadline, $buffer,
                function () use ($session, $permit, $context, $deadline): void {
                    $session->authorizeSend($context, $permit);
                    if (DB::transactionLevel() !== 0 || hrtime(true) / 1e9 >= $deadline) {
                        throw new TenantAiStorageUnavailable;
                    }
                });

            return $buffer->finish($policy->profile->model_key, $policy->account['organization_id'], $policy->account['workspace_id']);
        } catch (\Throwable $error) {
            return new CredentialVerificationEvidence(hrtime(true) / 1e9 >= $deadline ? 'timeout'
                : ($buffer->rejectionOutcome() ?? self::failureOutcome((int) $error->getCode())));
        } finally {
            unset($secret, $buffer);
        }
    }

    protected function wire(string $model, string $workspace, #[\SensitiveParameter] string $key, float $deadline,
        TenantAiVerificationResponseBuffer $buffer, \Closure $ready): void
    {
        if (app()->environment('testing') || class_exists(TestCase::class, false)
            || config('tenant_ai.verification.anthropic_egress_enabled') !== true) {
            throw new TenantAiStorageUnavailable;
        }
        $started = hrtime(true) / 1e9;
        $ip = $this->resolve(min($deadline, $started + 1));
        $remaining = $deadline - hrtime(true) / 1e9;
        $connect = min(2 - (hrtime(true) / 1e9 - $started), $remaining);
        if ($remaining <= 0 || $connect <= 0) {
            throw new TenantAiStorageUnavailable;
        }
        $handle = curl_init('https://api.anthropic.com:443/v1/models/'.rawurlencode($model));
        if (! $handle instanceof \CurlHandle) {
            throw new TenantAiStorageUnavailable;
        }
        try {
            if (! curl_setopt_array($handle, $this->options($workspace, $key, $deadline, $connect, $remaining, $ip, $buffer, $ready))) {
                throw new TenantAiStorageUnavailable;
            }
            if (curl_exec($handle) !== true) {
                throw new \RuntimeException('Verification transport failed.', curl_errno($handle));
            }
        } finally {
            curl_close($handle);
            unset($key, $ready);
        }
    }

    private static function failureOutcome(int $code): string
    {
        return match ($code) {
            CURLE_OPERATION_TIMEDOUT => 'timeout',
            CURLE_COULDNT_CONNECT, CURLE_SEND_ERROR, CURLE_RECV_ERROR, CURLE_GOT_NOTHING => 'provider_unavailable',
            default => 'transport_policy_rejected',
        };
    }

    /** @return array<int, mixed> */
    private function options(string $workspace, #[\SensitiveParameter] string $key, float $deadline, float $connect,
        float $remaining, string $ip, TenantAiVerificationResponseBuffer $buffer, \Closure $ready): array
    {
        return [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Encoding: identity', 'anthropic-version: 2023-06-01',
                'x-api-key: '.$key, 'anthropic-workspace-id: '.$workspace],
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) floor($connect * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) floor($remaining * 1000)),
            CURLOPT_RESOLVE => ['api.anthropic.com:443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_HTTP_CONTENT_DECODING => false, CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => fn (): int => connection_aborted() !== 0 || hrtime(true) / 1e9 >= $deadline ? 1 : 0,
            CURLOPT_HEADERFUNCTION => fn (\CurlHandle $curl, string $line): int => $buffer->header($line),
            CURLOPT_WRITEFUNCTION => fn (\CurlHandle $curl, string $chunk): int => $buffer->chunk($chunk),
            CURLOPT_PREREQFUNCTION => function (\CurlHandle $curl, string $remote, string $local, int $port, int $localPort) use ($ready, $ip): int {
                if (inet_pton($remote) !== inet_pton($ip) || $port !== 443) {
                    return CURL_PREREQFUNC_ABORT;
                }
                try {
                    $ready();
                } catch (\Throwable) {
                    return CURL_PREREQFUNC_ABORT;
                }

                return CURL_PREREQFUNC_OK;
            },
        ];
    }
}
