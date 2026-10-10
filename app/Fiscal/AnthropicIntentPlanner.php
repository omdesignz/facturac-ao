<?php

namespace App\Fiscal;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class AnthropicIntentPlanner implements AssistantPlanner
{
    public function __construct(private AssistantProviderLedger $ledger, private AssistantIntentGateway $gateway) {}

    public function plan(#[\SensitiveParameter] array $input, ?AssistantProviderInvocation $invocation = null): string
    {
        abort_unless($invocation instanceof AssistantProviderInvocation, 503);
        $permit = null;
        try {
            $invocation->requireHttp();
            $this->ledger->gates($invocation);
            $key = $this->secret();
            $permit = AssistantProviderPermit::admit($invocation, $this->gateway, $this->ledger);

            $plan = $this->gateway->execute($invocation, $permit, $key);
            $invocation->retain($permit);

            return $plan;
        } catch (\Throwable $error) {
            $status = $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [401, 403, 404, 429], true) ? $error->getStatusCode() : 503;
            if ($permit === null) {
                AssistantAudit::record($invocation->context, $status === 429 ? 'assistant.provider.quota_rejected' : 'assistant.provider.blocked',
                    ['provider_profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY,
                        'outcome' => $status === 429 ? 'quota_rejected' : 'unavailable']);
            }
            abort($status, '', $status === 429 ? ['Retry-After' => '60'] : []);
        }
    }

    private function secret(): string
    {
        $path = config('assistant.provider.secret_reference');
        abort_unless(is_string($path) && str_starts_with($path, '/') && ! is_link($path) && is_file($path) && is_readable($path), 503);
        $before = lstat($path);
        abort_unless(is_array($before) && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 077) === 0
            && $before['size'] >= 16 && $before['size'] <= 513, 503);
        $handle = fopen($path, 'rb');
        abort_unless(is_resource($handle), 503);
        try {
            $after = fstat($handle);
            abort_unless(is_array($after) && $after['ino'] === $before['ino'] && $after['dev'] === $before['dev']
                && ($after['mode'] & 077) === 0 && $after['size'] >= 16 && $after['size'] <= 513, 503);
            $key = stream_get_contents($handle, 514);
        } finally {
            fclose($handle);
        }
        abort_unless(is_string($key), 503);
        $key = rtrim($key, "\n");
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{16,512}$/D', $key) === 1, 503);

        return $key;
    }
}
