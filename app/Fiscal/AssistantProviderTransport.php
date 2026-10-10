<?php

namespace App\Fiscal;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fixed Anthropic transport. Tests override only wire(), never admission or bounds. */
class AssistantProviderTransport
{
    use ResolvesAnthropicAddress;

    final public function exchange(AssistantProviderInvocation $invocation, AssistantProviderPermit $permit,
        AssistantProviderLedger $ledger, #[\SensitiveParameter] string $key): string
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{16,512}$/D', $key) === 1, 503);
        $permit->consume($invocation, $ledger);
        $deadline = hrtime(true) / 1e9 + min(8, $invocation->remaining() - 0.05);
        $buffer = new AssistantProviderResponseBuffer($deadline);
        $fresh = function () use ($invocation, $permit, $ledger, $deadline): void {
            $permit->recheck($invocation, $ledger);
            abort_if(hrtime(true) / 1e9 >= $deadline, 503);
        };
        $fresh();
        $ready = function () use ($fresh, $permit, $invocation, $ledger): void {
            $fresh();
            $permit->markTransmission($invocation, $ledger);
        };
        $this->wire($permit->body, $key, $deadline, $buffer, $ready);
        $invocation->remaining();

        return $buffer->finish();
    }

    protected function wire(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $key,
        float $deadline, AssistantProviderResponseBuffer $buffer, \Closure $fresh): void
    {
        abort_if(app()->environment('testing') || config('assistant.provider.egress_enabled') !== true, 503);
        $started = hrtime(true) / 1e9;
        $ip = $this->resolve(min($deadline, $started + 1));
        $remaining = $deadline - hrtime(true) / 1e9;
        $connect = min(2 - (hrtime(true) / 1e9 - $started), $remaining);
        abort_if($remaining <= 0 || $connect <= 0, 503);
        $handle = curl_init(AssistantProviderProfile::URL);
        abort_unless($handle instanceof \CurlHandle, 503);
        $authorityStatus = null;
        try {
            abort_unless(curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: identity',
                    'anthropic-version: '.AssistantProviderProfile::API_VERSION, 'x-api-key: '.$key, 'Expect:'],
                CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) floor($connect * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) floor($remaining * 1000)),
                CURLOPT_RESOLVE => [AssistantProviderProfile::HOST.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
                CURLOPT_HTTP_CONTENT_DECODING => false,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => fn (): int => connection_aborted() !== 0 || hrtime(true) / 1e9 >= $deadline ? 1 : 0,
                CURLOPT_HEADERFUNCTION => fn (\CurlHandle $curl, string $line): int => $buffer->header($line),
                CURLOPT_WRITEFUNCTION => fn (\CurlHandle $curl, string $chunk): int => $buffer->chunk($chunk),
                CURLOPT_PREREQFUNCTION => function (\CurlHandle $curl, string $remote, string $local, int $remotePort, int $localPort) use ($fresh, $ip, &$authorityStatus): int {
                    if (inet_pton($remote) !== inet_pton($ip) || $remotePort !== 443) {
                        return CURL_PREREQFUNC_ABORT;
                    }
                    try {
                        $fresh();
                    } catch (\Throwable $error) {
                        if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [401, 403, 404], true)) {
                            $authorityStatus = $error->getStatusCode();
                        }

                        return CURL_PREREQFUNC_ABORT;
                    }

                    return CURL_PREREQFUNC_OK;
                },
            ]), 503);
            $complete = curl_exec($handle);
            if ($authorityStatus !== null) {
                abort($authorityStatus);
            }
            abort_unless($complete === true && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200, 503);
        } finally {
            curl_close($handle);
        }
    }
}
