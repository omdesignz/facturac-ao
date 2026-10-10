<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiInvocationRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** One transient, closed invocation. Persisted data cannot recreate its object authority. */
final class TenantAiInvocationSession implements AiSendAuthority
{
    /** new, admitting, issued, decrypt_claimed, exchange_started, send_authorized, closed: one way, never repeated. */
    private string $stage = 'new';

    private ?AssistantProviderInvocation $invocation = null;

    private ?Request $request = null;

    private int $pid = 0;

    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    private ?\Fiber $fiber = null;

    private ?TenantAiInvocationPolicy $policy = null;

    /** @var array<string, mixed> */
    private array $attempt = [];

    private ?CredentialInvocationPermit $permit = null;

    private ?TenantAiInvocationSecret $secret = null;

    private ?ProviderIntentInput $input = null;

    private ?string $body = null;

    private bool $observed = false;

    private bool $sendAuthorized = false;

    private bool $settled = false;

    private ?AiInvocationDenial $refusal = null;

    private bool $deniedAfterClose = false;

    private readonly AssistantIntentGateway $bridge;

    public function __construct(private readonly AssistantProviderTransport $transport)
    {
        $this->bridge = new AssistantIntentGateway($transport, new AssistantProviderLedger);
    }

    /** Returns the untrusted local plan; every failure is a fixed refusal without chaining. */
    public function run(AssistantProviderInvocation $invocation): string
    {
        if ($this->stage !== 'new' || DB::transactionLevel() !== 0) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RuntimeUnsupported);
        }
        $this->stage = 'admitting';
        $this->invocation = $invocation;
        $this->request = app('request');
        $this->pid = (int) getmypid();
        $this->fiber = \Fiber::getCurrent();
        try {
            $this->runtime();
            $policy = DB::transaction(function () use ($invocation): TenantAiInvocationPolicy {
                TenantAiVerificationAdmission::limits($this->deadline());

                return (new TenantAiInvocationPolicyResolver)->resolve($invocation, $this->realm(), false);
            }, 1);
            if ($policy->tools !== $invocation->tools()) {
                throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
            }
            $this->input = ProviderIntentInput::fromInput($invocation->input, $policy->permissions, $policy->tools);
            $this->body = $this->bridge->prepare($this->input);
            $admitted = (new TenantAiInvocationAdmission)->admit($this, $invocation, $this->body);
            $this->policy = $admitted['policy'];
            $this->attempt = $admitted['attempt'];
            $this->permit = new CredentialInvocationPermit;
            $this->stage = 'issued';
            $this->secret = TenantAiInvocationSecret::load($this, $this->permit);
            try {
                $plan = $this->secret->exchange($this, $this->permit);
            } finally {
                $this->secret = null;
            }
            $this->permit = null;
            $this->stage = 'closed';
            $invocation->retain($this);

            return $plan;
        } catch (\Throwable $error) {
            $this->permit = null;
            $this->secret = null;
            if ($this->attempt !== [] && ! $this->settled) {
                try {
                    $this->finalize(null, false, false, true);
                } catch (\Throwable) {
                    // Retained admission is recovered without replay, secret access or refund.
                }
            }
            $this->stage = 'closed';
            $reason = $this->refusal ?? ($error instanceof TenantAiInvocationRefused ? $error->reason : null);
            $recorded = $this->deniedAfterClose;
            if ($reason !== null && ! $recorded) {
                try {
                    TenantAiInvocationAdmission::audit($invocation->context, $this->attempt, 'invocation_credential_denied', $reason->value);
                    $recorded = true;
                } catch (\Throwable) {
                    // The fixed refusal below never depends on its own audit.
                }
            }
            if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [401, 403, 404, 429], true)) {
                abort($error->getStatusCode());
            }
            if ($reason !== null) {
                throw new TenantAiInvocationRefused($reason, $recorded);
            }
            throw new TenantAiStorageUnavailable;
        }
    }

    public function requireStage(string $stage): void
    {
        $this->bound();
        if ($this->stage !== $stage) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
    }

    /** Offline evidence is honoured only inside the disposable test harness that produced it. */
    public function realm(): string
    {
        if ($this->transport::class === AssistantProviderTransport::class) {
            return 'provider_tls';
        }
        if (PHP_SAPI !== 'cli' || ! app()->runningUnitTests() || ! class_exists(TestCase::class, false) || DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RuntimeUnsupported);
        }

        return 'offline_fixture';
    }

    public function claimDecrypt(CredentialInvocationPermit $permit): void
    {
        $this->bound();
        if ($this->permit === null || $permit !== $this->permit || $this->stage !== 'issued') {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
        $this->stage = 'decrypt_claimed';
    }

    /** Locked re-resolution inside the caller's transaction; equal to the admitted snapshot or refused. */
    public function fresh(CredentialInvocationPermit $permit): TenantAiInvocationPolicy
    {
        $this->bound();
        if ($this->permit === null || $permit !== $this->permit || $this->stage !== 'decrypt_claimed' || $this->observed) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
        TenantAiVerificationAdmission::limits($this->deadline());

        return $this->current(true);
    }

    public function observeSecret(TenantAiInvocationSecret $secret, CredentialInvocationPermit $permit, #[\SensitiveParameter] string $plaintext): string
    {
        $this->bound();
        if ($secret !== $this->secret || $this->permit === null || $permit !== $this->permit || $this->stage !== 'decrypt_claimed' || $this->observed) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
        $this->observed = true;

        return $this->bridge->execute($this->invocation, $this, $plaintext);
    }

    public function input(): ProviderIntentInput
    {
        return $this->input ?? throw new TenantAiStorageUnavailable;
    }

    public function permissions(): array
    {
        return $this->policy?->permissions ?? throw new TenantAiStorageUnavailable;
    }

    public function body(): string
    {
        return $this->body ?? throw new TenantAiStorageUnavailable;
    }

    public function matchesBody(#[\SensitiveParameter] string $body): bool
    {
        return $this->body !== null && hash_equals(hash('sha256', $this->body), hash('sha256', $body));
    }

    public function consume(): void
    {
        $this->guarded(function (): void {
            $this->bound();
            if ($this->stage !== 'decrypt_claimed' || ! $this->observed) {
                throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
            }
            $this->stage = 'exchange_started';
            if (! DB::table('assistant_provider_attempts')->useWritePdo()->where('id', $this->attempt['id'])->where('purpose', 'assistant_intent')
                ->where('state', 'admitted')->whereNull('send_authorized_at')->whereNull('finalized_at')->exists()) {
                throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
            }
            $this->current(false);
        });
    }

    public function recheck(): void
    {
        $this->guarded(function (): void {
            $this->bound();
            $this->current(false);
        });
    }

    public function gate(): void
    {
        $this->recheck();
    }

    /** The linearization point is the commit of this transaction; at most one send is ever authorized. */
    public function authorizeSend(): void
    {
        $this->guarded(function (): void {
            $this->bound();
            if ($this->stage !== 'exchange_started' || DB::transactionLevel() !== 0) {
                throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
            }
            $this->stage = 'send_authorized';
            DB::transaction(function (): void {
                TenantAiVerificationAdmission::limits($this->deadline());
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
                $this->current(true);
                $row = DB::table('assistant_provider_attempts')->where('id', $this->attempt['id'])->where('contract_version', 'gateway_v1')
                    ->where('purpose', 'assistant_intent')->lockForUpdate()->first();
                if ($row === null || $row->state !== 'admitted' || $row->send_authorized_at !== null) {
                    throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
                }
                $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
                if (DB::table('assistant_provider_attempts')->where('id', $row->id)->update(['send_authorized_at' => $time]) !== 1
                    || DB::table('tenant_ai_credentials')->where('id', $this->attempt['credential_version_id'])->update(['last_used_at' => $time]) !== 1) {
                    throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
                }
                TenantAiInvocationAdmission::audit($this->invocation->context, $this->attempt, 'invocation_credential_selected', 'send_authorized');
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->invocation->permissions();
            }, 1);
            $this->sendAuthorized = true;
            $this->bound();
        });
    }

    public function transmissionStarted(): bool
    {
        return $this->sendAuthorized;
    }

    /** Never requires current authority; the persisted send mark, not the caller, decides what was possibly sent. */
    public function finalize(?array $usage, bool $received, bool $anomaly, bool $notSent): void
    {
        if ($this->attempt === [] || $this->invocation === null || $this->settled) {
            throw new TenantAiStorageUnavailable;
        }
        (new TenantAiInvocationAdmission)->settle($this->attempt['id'], $usage, $received, $anomaly, $this->invocation->context);
        $this->settled = true;
    }

    private function current(bool $lock): TenantAiInvocationPolicy
    {
        if ($this->policy === null || $this->invocation === null || $this->body === null) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
        try {
            $fresh = (new TenantAiInvocationPolicyResolver)->resolve($this->invocation, $this->realm(), $lock);
        } catch (TenantAiInvocationRefused) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }
        foreach ((new TenantAiInvocationAdmission)->snapshot($this->invocation, $fresh, $this->body) as $field => $value) {
            if (! array_key_exists($field, $this->attempt) || $this->attempt[$field] !== $value) {
                throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
            }
        }
        if ($fresh->configurationDigest !== $this->policy->configurationDigest || $fresh->tools !== $this->policy->tools
            || array_diff($this->policy->permissions, $fresh->permissions) !== []) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::AuthorityChanged);
        }

        return $fresh;
    }

    private function bound(): void
    {
        if ($this->invocation === null || $this->request === null || getmypid() !== $this->pid || \Fiber::getCurrent() !== $this->fiber
            || app('request') !== $this->request || $this->request->attributes->get('assistant_context') !== $this->invocation->context
            || $this->request->attributes->get('assistant_guard') !== $this->invocation->guard || ! $this->request->routeIs('assistant.store')) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RuntimeUnsupported);
        }
        $this->deadline();
    }

    private function deadline(): float
    {
        try {
            return hrtime(true) / 1e9 + $this->invocation->remaining();
        } catch (\Throwable) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::DeadlineExceeded);
        }
    }

    /** Synchronous PHP-FPM requests only; console, queue and long-lived workers are refused. */
    private function runtime(): void
    {
        try {
            $this->invocation->requireHttp();
        } catch (\Throwable) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RuntimeUnsupported);
        }
        $sapis = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', ...(app()->runningUnitTests() ? ['cli', 'phpdbg'] : [])];
        if (DB::getDriverName() !== 'pgsql' || ! in_array(PHP_SAPI, $sapis, true) || isset($_SERVER['LARAVEL_OCTANE']) || isset($_SERVER['FRANKENPHP_WORKER']) || isset($_SERVER['RR_MODE'])
            || app()->bound('octane') || app()->resolved('queue.worker') || extension_loaded('swoole') || extension_loaded('openswoole')) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RuntimeUnsupported);
        }
        $this->bound();
    }

    /** Remembers the closed reason and surfaces only the fixed unavailable status, as the accepted permit does. */
    private function guarded(\Closure $operation): void
    {
        try {
            $operation();
        } catch (TenantAiInvocationRefused $refusal) {
            $this->refusal ??= $refusal->reason;
            if ($this->stage === 'closed' && ! $this->deniedAfterClose && $this->invocation !== null) {
                $this->deniedAfterClose = true;
                try {
                    TenantAiInvocationAdmission::audit($this->invocation->context, $this->attempt, 'invocation_credential_denied', $refusal->reason->value);
                } catch (\Throwable) {
                    // The fixed refusal below never depends on its own audit.
                }
            }
            abort(503);
        }
    }

    private function __clone() {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['invocation' => 'restricted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
