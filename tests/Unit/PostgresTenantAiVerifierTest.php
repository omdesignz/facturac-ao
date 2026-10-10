<?php

use App\Actions\DeleteUserAccount;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\CredentialPromotionPermit;
use App\Fiscal\TenantAiActiveLifecycle;
use App\Fiscal\TenantAiCredentialVerifier;
use App\Fiscal\TenantAiEmergencyContext;
use App\Fiscal\TenantAiVerificationAdmission;
use App\Fiscal\TenantAiVerificationContext;
use App\Fiscal\TenantAiVerificationPolicyResolver;
use App\Fiscal\TenantAiVerificationReceipt;
use App\Fiscal\TenantAiVerificationReceiptKeyFile;
use App\Fiscal\TenantAiVerificationRecovery;
use App\Fiscal\TenantAiVerificationRequest;
use App\Fiscal\TenantAiVerificationResponseBuffer;
use App\Fiscal\VerificationRequestPermit;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql', 'verification-runtime');
require_once __DIR__.'/../TenantAiVerifierFixtures.php';

beforeEach(function () {
    if (getenv('VERIFICATION_QUOTA_PG_GATE') !== '1') {
        $this->markTestSkipped('Dedicated disposable PostgreSQL verification runtime gate.');
    }
    expect(DB::getDriverName())->toBe('pgsql')->and(DB::connection()->getDatabaseName())->toBe('facturac_test_verification_cr1_runtime');
    $this->verificationRoots = [];
    Http::preventStrayRequests();
});
afterEach(function () {
    foreach ($this->verificationRoots ?? [] as $root) {
        foreach (['keks', 'receipts'] as $directory) {
            foreach (glob($root.'/'.$directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($root.'/'.$directory);
        }
        rmdir($root);
    }
    Http::assertNothingSent();
});

test('complete offline verification creates authentic active authority without provider calls', function () {
    $fixture = verifierFixture($this);
    $policy = (new TenantAiVerificationPolicyResolver)->resolve($fixture['context'], $fixture['command'], false);
    expect(strlen((new TenantAiVerificationReceiptKeyFile)->read('offline-test', $fixture['row']['deployment_id'], $policy->manifest['verifier_policy_sha256'], true)))->toBe(32);
    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'pg_advisory_xact_lock(')) {
            $locks[] = $query->sql;
        }
    });
    $verifier = offlineVerifier($fixture);
    $result = $verifier->verify($fixture['context'], $fixture['command']);
    expect($locks[0])->toContain('pg_advisory_xact_lock(')->and($locks[1])->toContain('ai_gateway_controls')
        ->and(count(array_filter($locks, fn ($sql) => str_contains($sql, 'pg_advisory_xact_lock('))))->toBe(1)
        ->and(fn () => $verifier->verify($fixture['context'], $fixture['command']))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => (new TenantAiActiveLifecycle)->promote($verifier, $fixture['context'], new CredentialPromotionPermit))->toThrow(TenantAiStorageUnavailable::class);
    expect($result->status)->toBe('verified-and-active');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    $credential = DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->firstOrFail();
    expect($credential->state)->toBe('active')->and($credential->verification_state)->toBe('verified')
        ->and($attempt->state)->toBe('usage_unknown')->and($attempt->actual_micro_usd)->toBeNull()
        ->and($attempt->evidence_realm)->toBe('offline_fixture')->and($attempt->promotion_disposition)->toBe('promoted')
        ->and($attempt->workspace_public_id)->toBe($fixture['row']['workspace_public_id'])
        ->and($attempt->legal_entity_public_id)->toBe($fixture['row']['legal_entity_public_id'])
        ->and($credential->last_used_at)->toBeNull();
    $key = file_get_contents($fixture['root'].'/receipts/key');
    expect(TenantAiVerificationReceipt::authentic(TenantAiVerificationReceipt::fields((array) $attempt), $attempt->receipt_mac, $key))->toBeTrue()
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9);
});

test('provider failures retain nine reservations without authenticating or refunding', function (int $status, string $outcome, string $verification) {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture, function ($buffer) use ($status) {
        $buffer->header("HTTP/1.1 $status Synthetic\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header("\r\n");
        $buffer->chunk('{"error":"synthetic upstream secret sentinel"}');
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verification-rejected');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    $credential = DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->firstOrFail();
    expect($attempt->verification_outcome)->toBe($outcome)->and($attempt->receipt_mac)->toBeNull()->and($attempt->actual_micro_usd)->toBeNull()
        ->and($credential->state)->toBe('pending')->and($credential->verification_state)->toBe($verification)
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9)
        ->and(DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->select('window_id'))->sum('unknown_usage_count'))->toBe('9');
    $audit = DB::table('activity_log')->where('event', 'like', 'assistant.ai.verification%')->pluck('properties')->implode('');
    expect($audit)->not->toContain('synthetic upstream secret sentinel')->not->toContain($fixture['secret']);
})->with([[401, 'credential_rejected', 'failed'], [403, 'provider_denied', 'unverified'], [404, 'model_unavailable', 'unverified'],
    [429, 'provider_unavailable', 'unverified'], [500, 'provider_unavailable', 'unverified']]);

test('stale owner authority after the wire observation cannot promote', function () {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture, function ($buffer, $fixture) {
        DB::table('workspace_memberships')->where('id', $fixture['owner']->membershipId)->update(['is_active' => false]);
        $buffer->header("HTTP/1.1 200 OK\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
        $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
        $buffer->header("\r\n");
        $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('credential-state-changed');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($attempt->promotion_disposition)->toBe('rejected_stale')->and($attempt->receipt_mac)->not->toBeNull()
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
});

test('forged promotion and decrypt handles cannot create session authority', function () {
    $fixture = verifierFixture($this);
    $verifier = new TenantAiCredentialVerifier;
    expect(fn () => $verifier->claimDecrypt($fixture['context'], new VerificationRequestPermit))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => (new TenantAiActiveLifecycle)->promote($verifier, $fixture['context'], new CredentialPromotionPermit))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => serialize($verifier))->toThrow(TenantAiStorageUnavailable::class)
        ->and(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->exists())->toBeFalse();
});

function conflictingVerificationFraming(TenantAiVerificationResponseBuffer $buffer, array $fixture, bool $reverse = false): void
{
    $body = json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']], JSON_THROW_ON_ERROR);
    $framing = ['tRaNsFeR-EnCoDiNg: chunked', 'cOnTeNt-LeNgTh: '.strlen($body)];
    $buffer->header("HTTP/1.1 200 OK\r\n");
    foreach (['Content-Type: application/json', ...($reverse ? array_reverse($framing) : $framing),
        'anthropic-organization-id: '.$fixture['account']['organization_id'],
        'anthropic-workspace-id: '.$fixture['account']['workspace_id'], ''] as $line) {
        expect($buffer->header($line."\r\n"))->toBe(strlen($line) + 2);
    }
    expect($buffer->chunk($body))->toBe(strlen($body));
}

test('conflicting framing cannot sign a receipt or promote and retains admitted liability', function (bool $reverse) {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture, fn ($buffer, $fixture) => conflictingVerificationFraming($buffer, $fixture, $reverse))
        ->verify($fixture['context'], $fixture['command']);
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($result->status)->toBe('verification-rejected')->and($attempt->verification_outcome)->toBe('malformed_response')
        ->and($attempt->receipt_mac)->toBeNull()->and($attempt->promotion_disposition)->toBe('not_applicable')
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending')
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9)
        ->and(DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->select('window_id'))->sum('unknown_usage_count'))->toBe('9')
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_promoted')->where('properties->id', $attempt->id)->exists())->toBeFalse();
})->with([false, true]);

test('real cooldown followed by active rotation preserves old secret on rollback and destroys it only on commit', function () {
    $fixtures = [];
    foreach (['commit', 'verify-only', 'untrusted-history', 'receipt', 'retirement', 'activation', 'selection', 'audit', 'ambiguous-framing'] as $fault) {
        $fixture = verifierFixture($this);
        $result = offlineVerifier($fixture)->verify($fixture['context'], $fixture['command']);
        expect($result->status)->toBe('verified-and-active');
        $fixture['configuration'] = config('tenant_ai');
        $fixture['old_id'] = $fixture['command']->credentialId;
        $fixture['first_attempt'] = $result->operationId;
        $fixtures[$fault] = $fixture;
    }
    $until = microtime(true) + 65;
    while (DB::table('assistant_provider_attempts')->whereIn('id', array_column($fixtures, 'first_attempt'))
        ->whereRaw("admitted_at > clock_timestamp() - interval '60 seconds'")->exists()) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Real verification cooldown did not elapse');
        }
        usleep(100000);
    }
    foreach ($fixtures as $fault => $fixture) {
        config(['tenant_ai' => $fixture['configuration']]);
        app()->instance('request', $fixture['request']);
        auth('web')->login($fixture['user']);
        if (in_array($fault, ['verify-only', 'untrusted-history'], true)) {
            $oldKey = config('tenant_ai.verification.receipt_keys.offline-test');
            $newKey = [...$oldKey, 'path' => $fixture['root'].'/receipts/rotated'];
            file_put_contents($newKey['path'], random_bytes(32));
            chmod($newKey['path'], 0600);
            config(['tenant_ai.verification.receipt_keys.offline-test.signing' => false,
                'tenant_ai.verification.receipt_keys.offline-test.trusted' => $fault === 'verify-only',
                'tenant_ai.verification.receipt_keys.offline-rotated' => $newKey,
                'tenant_ai.verification.signing_key_id' => 'offline-rotated']);
        }
        $newSecret = 'synthetic-rotated-secret-'.bin2hex(random_bytes(20));
        $candidate = (new TenantAiActiveLifecycle)->candidate($fixture['owner'], $fixture['connection'], 3, 4, null, $newSecret);
        $grant = (array) DB::table('assistant_provider_tenants')->where('id', $fixture['row']['owner_approval_id'])->firstOrFail();
        unset($grant['id']);
        $grant['selection_revision'] = 3;
        DB::table('assistant_provider_tenants')->insert($grant);
        $fixture['secret'] = $newSecret;
        $fixture['command'] = new TenantAiVerificationRequest($fixture['connection'], $candidate['id'], 3, 5, $fixture['old_id'], 1);
        $fixture['context'] = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
        $old = (array) DB::table('tenant_ai_credentials')->where('id', $fixture['old_id'])->firstOrFail();
        $target = match ($fault) {
            'receipt' => ['assistant_provider_attempts', 'UPDATE', "NEW.promotion_disposition='promoted'"],
            'retirement' => ['tenant_ai_credentials', 'UPDATE', "NEW.state='replaced'"],
            'activation' => ['tenant_ai_credentials', 'UPDATE', "NEW.state='active'"],
            'selection' => ['tenant_ai_settings', 'UPDATE', 'NEW.revision=4'],
            'audit' => ['activity_log', 'INSERT', "NEW.event='assistant.ai.credential_promoted'"],
            default => null,
        };
        if ($target !== null) {
            DB::unprepared("CREATE FUNCTION verification_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$target[2]} THEN RAISE EXCEPTION 'offline injected completion failure'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared("CREATE TRIGGER verification_fault BEFORE {$target[1]} ON {$target[0]} FOR EACH ROW EXECUTE FUNCTION verification_fault()");
        }
        try {
            $result = offlineVerifier($fixture, $fault === 'ambiguous-framing' ? conflictingVerificationFraming(...) : null)
                ->verify($fixture['context'], $fixture['command']);
        } finally {
            if ($target !== null) {
                DB::unprepared("DROP TRIGGER verification_fault ON {$target[0]}");
                DB::unprepared('DROP FUNCTION verification_fault()');
            }
        }
        if ($fault === 'ambiguous-framing') {
            $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
            expect($result->status)->toBe('verification-rejected')->and($attempt->verification_outcome)->toBe('malformed_response')
                ->and($attempt->receipt_mac)->toBeNull()->and($attempt->promotion_disposition)->toBe('not_applicable')
                ->and((array) DB::table('tenant_ai_credentials')->where('id', $fixture['old_id'])->firstOrFail())->toBe($old)
                ->and(DB::table('tenant_ai_credentials')->where('id', $candidate['id'])->value('state'))->toBe('pending')
                ->and(DB::table('tenant_ai_settings')->where('workspace_id', $fixture['row']['workspace_id'])->value('credential_version_id'))->toBe($fixture['old_id'])
                ->and(DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->value('active_generation'))->toBe(1)
                ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9)
                ->and(DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->select('window_id'))->where('unknown_usage_count', '>=', 1)->count())->toBe(9);
        } elseif ($fault === 'untrusted-history') {
            expect($result->status)->toBe('credential-state-changed')
                ->and((array) DB::table('tenant_ai_credentials')->where('id', $fixture['old_id'])->firstOrFail())->toBe($old)
                ->and(DB::table('assistant_provider_attempts')->where('id', $result->operationId)->value('promotion_disposition'))->toBe('rejected_stale');
        } elseif (in_array($fault, ['commit', 'verify-only'], true)) {
            expect($result->status)->toBe('verified-and-active')
                ->and(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->count())->toBe(1);
            $retired = DB::table('tenant_ai_credentials')->where('id', $fixture['old_id'])->firstOrFail();
            expect($retired->state)->toBe('replaced')->and($retired->secret_ciphertext)->toBeNull()->and($retired->wrapped_dek)->toBeNull()
                ->and($retired->kek_version)->toBeNull()->and(DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->value('active_generation'))->toBe(2);
        } else {
            expect($result->status)->toBe('verification-unavailable')
                ->and((array) DB::table('tenant_ai_credentials')->where('id', $fixture['old_id'])->firstOrFail())->toBe($old)
                ->and(DB::table('tenant_ai_settings')->where('workspace_id', $fixture['row']['workspace_id'])->value('credential_version_id'))->toBe($fixture['old_id'])
                ->and(DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->value('active_generation'))->toBe(1)
                ->and(DB::table('assistant_provider_attempts')->where('id', $result->operationId)->value('state'))->toBe('admitted');
        }
    }
});

test('same candidate concurrent sessions perform exactly one verification and one activation', function () {
    $fixture = verifierFixture($this);
    $results = quotaRace(function () use ($fixture) {
        $context = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
        $result = offlineVerifier($fixture)->verify($context, $fixture['command']);
        if ($result->status !== 'verified-and-active') {
            throw new TenantAiStorageUnavailable;
        }
    });
    expect(count(array_filter($results, fn ($result) => $result['result'] === 'committed')))->toBe(1)
        ->and(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->count())->toBe(1)
        ->and(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->count())->toBe(1);
});

function verificationChangeInProcess(array $fixture, string $change): void
{
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        try {
            $emergency = TenantAiEmergencyContext::resolve($fixture['request'], $fixture['row']['workspace_public_id']);
            $lifecycle = new TenantAiActiveLifecycle;
            match ($change) {
                'revoke' => $lifecycle->revoke($emergency, $fixture['connection'], $fixture['command']->credentialId, 2, 3),
                'replace' => $lifecycle->candidate($fixture['owner'], $fixture['connection'], 2, 3, $fixture['command']->credentialId, 'synthetic-new-candidate'),
                'workspace disable' => $lifecycle->disableWorkspace($emergency, 2),
                'connection disable' => $lifecycle->disableConnection($emergency, $fixture['connection'], 2, 3),
                'policy' => DB::transaction(function () use ($fixture) {
                    DB::table('ai_gateway_controls')->where('deployment_id', $fixture['row']['deployment_id'])->where('kind', 'global')->lockForUpdate()->firstOrFail();
                    DB::table('ai_gateway_controls')->where('deployment_id', $fixture['row']['deployment_id'])->where('kind', 'provider')->update(['circuit_blocked' => true, 'revision' => 2]);
                }),
            };
            DB::disconnect();
            exit(0);
        } catch (Throwable) {
            exit(9);
        }
    }
    expect($pid)->toBeGreaterThan(0);
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status))->toBe(0);
    DB::purge();
}

test('committed lifecycle changes before send or completion defeat stale verification across processes', function (string $change, bool $beforeSend) {
    $fixture = verifierFixture($this);
    $changeOperation = fn (array $fixture) => verificationChangeInProcess($fixture, $change);
    $wire = $beforeSend ? null : function ($buffer, $fixture) use ($changeOperation) {
        $changeOperation($fixture);
        $buffer->header("HTTP/1.1 200 OK\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
        $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
        $buffer->header("\r\n");
        $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
    };
    $result = offlineVerifier($fixture, $wire, $beforeSend ? $changeOperation : null)->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe($beforeSend ? 'verification-rejected' : 'credential-state-changed');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($attempt->send_authorized_at === null)->toBe($beforeSend)
        ->and($attempt->promotion_disposition)->toBe($beforeSend ? 'not_applicable' : 'rejected_stale')
        ->and(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->exists())->toBeFalse();
})->with(['revoke', 'replace', 'workspace disable', 'connection disable', 'policy'])->with([true, false]);

test('preflight rejection never reaches plaintext or the wire', function (string $change) {
    $fixture = verifierFixture($this);
    match ($change) {
        'switch' => config(['tenant_ai.verification.enabled' => false]),
        'egress' => config(['tenant_ai.verification.anthropic_egress_enabled' => false]),
        'profile' => config(['tenant_ai.verification.profiles' => []]),
        'mapping' => config(['tenant_ai.verification.accounts' => []]),
        'signer trust' => config(['tenant_ai.verification.receipt_keys.offline-test.trusted' => false]),
        'signer validity' => config(['tenant_ai.verification.receipt_keys.offline-test.valid_until' => now()->subMinute()->toIso8601String()]),
        'signer permissions' => chmod($fixture['root'].'/receipts/key', 0644),
        'bearer' => $fixture['request']->headers->set('Authorization', 'Bearer synthetic'),
        'reauth' => $fixture['request']->session()->put('auth.password_confirmed_at', time() - 301),
        'request' => app()->instance('request', clone $fixture['request']),
        'membership' => DB::table('workspace_memberships')->where('id', $fixture['owner']->membershipId)->update(['is_active' => false]),
        'root' => DB::table('ai_gateway_controls')->where('deployment_id', $fixture['row']['deployment_id'])->where('kind', 'global')->update(['circuit_blocked' => true, 'revision' => 3]),
    };
    $sent = false;
    $result = offlineVerifier($fixture, function () use (&$sent) {
        $sent = true;
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verification-unavailable')->and($sent)->toBeFalse()
        ->and(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->exists())->toBeFalse()
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
})->with(['switch', 'egress', 'profile', 'mapping', 'signer trust', 'signer validity', 'signer permissions', 'bearer', 'reauth', 'request', 'membership', 'root']);

test('fresh context rejects foreign entity environment and interleaved fiber', function () {
    $fixture = verifierFixture($this);
    foreach (['homologation', 'PRODUCTION', ''] as $environment) {
        expect(fn () => TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], $environment))
            ->toThrow(TenantAiStorageUnavailable::class);
    }
    $foreign = LegalEntity::factory()->create();
    expect(fn () => TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $foreign->public_id, 'production'))
        ->toThrow(TenantAiStorageUnavailable::class);
    $fiber = new Fiber(fn () => $fixture['context']->authorize());
    expect(fn () => $fiber->start())->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => serialize($fixture['context']))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => clone $fixture['context'])->toThrow(Error::class);
});

test('current authority expiry bounds the signed promotion lifetime', function () {
    $fixture = verifierFixture($this);
    $expiry = TenantAiVerificationAdmission::instant()->addSeconds(3);
    DB::table('assistant_provider_controls')->where('budget_id', $fixture['row']['usage_budget_id'])->update([
        'approval_expires_at' => $expiry->toIso8601String(), 'revision' => 3]);
    $result = offlineVerifier($fixture)->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verified-and-active');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect(CarbonImmutable::parse($attempt->promotion_expires_at)->lessThanOrEqualTo($expiry))->toBeTrue();
});

test('withdrawn acknowledgement or key trust after observation never promotes', function (string $change) {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture, function ($buffer, $fixture) use ($change) {
        if ($change === 'acknowledgement') {
            DB::table('assistant_provider_acknowledgements')->where('id', $fixture['row']['acknowledgement_id'])->update(['revoked_at' => now()->toIso8601String()]);
        } else {
            config(['tenant_ai.verification.receipt_keys.offline-test.trusted' => false]);
        }
        $buffer->header("HTTP/1.1 200 OK\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
        $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
        $buffer->header("\r\n");
        $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe($change === 'acknowledgement' ? 'credential-state-changed' : 'verification-unavailable')
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($attempt->promotion_disposition)->toBe($change === 'acknowledgement' ? 'rejected_stale' : null);
})->with(['acknowledgement', 'key']);

test('crashed workers recover after thirty seconds without receipt refund network or repeat accounting', function () {
    $fixtures = [];
    foreach (['before send', 'after send', 'after body', 'after observation'] as $point) {
        $fixture = verifierFixture($this);
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            $context = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
            $wire = function ($buffer) use ($point, $fixture) {
                if ($point === 'after observation') {
                    $buffer->header("HTTP/1.1 200 OK\r\n");
                    $buffer->header("Content-Type: application/json\r\n");
                    $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
                    $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
                    $buffer->header("\r\n");
                    $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
                    DB::listen(function ($query): void {
                        if ($query->sql === 'SELECT clock_timestamp() AS instant') {
                            DB::disconnect();
                            exit(23);
                        }
                    });

                    return;
                }
                if ($point === 'after body') {
                    $buffer->header("HTTP/1.1 200 OK\r\n");
                    $buffer->header("\r\n");
                    $buffer->chunk('{"type":"model"}');
                }
                DB::disconnect();
                exit(23);
            };
            offlineVerifier($fixture, $wire, $point === 'before send' ? function () {
                DB::disconnect();
                exit(23);
            } : null)->verify($context, $fixture['command']);
            exit(24);
        }
        expect($pid)->toBeGreaterThan(0);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(23);
        DB::purge();
        $attempt = DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->firstOrFail();
        expect($attempt->state)->toBe('admitted')->and($attempt->send_authorized_at === null)->toBe($point === 'before send');
        $fixtures[] = $attempt;
    }
    $ids = array_map(fn ($row) => $row->id, $fixtures);
    (new TenantAiVerificationRecovery)->recover();
    expect(DB::table('assistant_provider_attempts')->whereIn('id', $ids)->where('state', 'admitted')->count())->toBe(4);
    $until = microtime(true) + 33;
    while (DB::table('assistant_provider_attempts')->whereIn('id', $ids)->whereRaw("admitted_at >= clock_timestamp() - interval '30 seconds'")->exists()) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Recovery wall clock expired');
        }
        usleep(100000);
    }
    $recovery = new TenantAiVerificationRecovery;
    DB::unprepared("CREATE FUNCTION verification_recovery_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event='assistant.ai.verification_failed' THEN RAISE EXCEPTION 'offline recovery audit failure'; END IF; RETURN NEW; END; \$\$");
    DB::unprepared('CREATE TRIGGER verification_recovery_fault BEFORE INSERT ON activity_log FOR EACH ROW EXECUTE FUNCTION verification_recovery_fault()');
    try {
        expect(fn () => $recovery->recover())->toThrow(TenantAiStorageUnavailable::class);
        expect(DB::table('assistant_provider_attempts')->whereIn('id', $ids)->where('state', 'admitted')->count())->toBe(4);
        foreach ($ids as $id) {
            expect(DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $id)->select('window_id'))->sum('unknown_usage_count'))->toBe(0);
        }
    } finally {
        DB::unprepared('DROP TRIGGER verification_recovery_fault ON activity_log');
        DB::unprepared('DROP FUNCTION verification_recovery_fault()');
    }
    $recovery->recover();
    foreach ($ids as $id) {
        $row = DB::table('assistant_provider_attempts')->where('id', $id)->firstOrFail();
        expect($row->state)->toBe('usage_unknown')->and($row->promotion_disposition)->toBe('abandoned')->and($row->receipt_mac)->toBeNull()
            ->and($row->actual_micro_usd)->toBeNull();
        $windows = DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $id)->select('window_id'));
        expect($windows->sum('unknown_usage_count'))->toBe('9')->and($windows->sum('reserved_attempt_units'))->toBe('5');
    }
    $before = DB::table('assistant_provider_attempts')->whereIn('id', $ids)->orderBy('id')->get()->toJson();
    expect($recovery->recover())->toBe(0)
        ->and(DB::table('assistant_provider_attempts')->whereIn('id', $ids)->orderBy('id')->get()->toJson())->toBe($before);
});

test('signed authority changes after send cannot acquire promotion authority', function (string $change) {
    $fixture = verifierFixture($this);
    $changed = false;
    $result = offlineVerifier($fixture, function ($buffer, $fixture) use ($change, &$changed) {
        $row = $fixture['row'];
        match ($change) {
            'root revision' => DB::table('ai_gateway_controls')->where('deployment_id', $row['deployment_id'])->where('kind', 'global')->update(['revision' => 3]),
            'provider revision' => DB::table('ai_gateway_controls')->where('deployment_id', $row['deployment_id'])->where('kind', 'provider')->update(['revision' => 2]),
            'model revision' => DB::table('ai_gateway_controls')->where('deployment_id', $row['deployment_id'])->where('kind', 'model')->update(['revision' => 2]),
            'usage revision' => DB::table('assistant_provider_controls')->where('budget_id', $row['usage_budget_id'])->update(['revision' => 3]),
            'aggregate revision' => DB::table('assistant_provider_controls')->where('budget_id', $row['aggregate_budget_id'])->update(['revision' => 3]),
            'account revision' => DB::table('assistant_provider_controls')->where('budget_id', $row['budget_id'])->update(['revision' => 3]),
            'approval withdrawal' => DB::table('assistant_provider_tenants')->where('id', $row['owner_approval_id'])->update(['revoked_at' => now()->toIso8601String()]),
            'settings revision' => DB::table('tenant_ai_settings')->where('workspace_id', $row['workspace_id'])->update(['revision' => 3]),
            'connection revision' => DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->update(['revision' => 4]),
            'manifest digest' => config(['tenant_ai.verification.profiles.'.$row['profile_id'].'.profile_manifest_sha256' => str_repeat('0', 64)]),
            'account mapping' => config(['tenant_ai.verification.accounts.'.$row['budget_id'].'.mapping_sha256' => str_repeat('0', 64)]),
            'account expiry' => config(['tenant_ai.verification.accounts.'.$row['budget_id'].'.expires_at' => now()->subMinute()->toIso8601String()]),
            'deployment' => config(['tenant_ai.deployment_id' => (string) Str::uuid()]),
        };
        $changed = true;
        $buffer->header("HTTP/1.1 200 OK\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
        $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
        $buffer->header("\r\n");
        $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
    })->verify($fixture['context'], $fixture['command']);
    expect($changed)->toBeTrue()->and($result->status)->toBe('credential-state-changed')
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending')
        ->and(DB::table('assistant_provider_attempts')->where('id', $result->operationId)->value('promotion_disposition'))->toBe('rejected_stale');
})->with(['root revision', 'provider revision', 'model revision', 'usage revision', 'aggregate revision', 'account revision',
    'approval withdrawal', 'settings revision', 'connection revision', 'manifest digest', 'account mapping', 'account expiry', 'deployment']);

test('admission and send audit failures roll back the corresponding authority boundary', function (string $event) {
    $fixture = verifierFixture($this);
    $sent = false;
    DB::unprepared("CREATE FUNCTION verification_audit_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event='assistant.ai.$event' THEN RAISE EXCEPTION 'offline audit failure'; END IF; RETURN NEW; END; \$\$");
    DB::unprepared('CREATE TRIGGER verification_audit_fault BEFORE INSERT ON activity_log FOR EACH ROW EXECUTE FUNCTION verification_audit_fault()');
    try {
        $result = offlineVerifier($fixture, function () use (&$sent) {
            $sent = true;
        })->verify($fixture['context'], $fixture['command']);
    } finally {
        DB::unprepared('DROP TRIGGER verification_audit_fault ON activity_log');
        DB::unprepared('DROP FUNCTION verification_audit_fault()');
    }
    expect($sent)->toBeFalse()->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
    $attempt = DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->first();
    if ($event === 'verification_attempted') {
        expect($attempt)->toBeNull()->and($result->operationId)->toBeNull();
    } else {
        expect($attempt->send_authorized_at)->toBeNull()->and($attempt->receipt_mac)->toBeNull()
            ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9);
    }
})->with(['verification_attempted', 'verification_send_authorized']);

test('callback-aborted bounded wire cannot leak raw upstream errors or authenticate', function () {
    $fixture = verifierFixture($this);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = [$query->sql, $query->bindings];
    });
    $result = offlineVerifier($fixture, function ($buffer) {
        expect($buffer->header('X: '.str_repeat('secret-header-sentinel', 300)."\r\n"))->toBe(0);
        throw new RuntimeException('raw-provider-error-sentinel');
    })->verify($fixture['context'], $fixture['command']);
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($result->status)->toBe('verification-rejected')->and($attempt->verification_outcome)->toBe('transport_policy_rejected')
        ->and($attempt->receipt_mac)->toBeNull();
    $captured = json_encode([$queries, $result, DB::table('activity_log')->where('event', 'like', 'assistant.ai.%')->pluck('properties')->all()]);
    expect($captured)->not->toContain($fixture['secret'])->not->toContain('secret-header-sentinel')->not->toContain('raw-provider-error-sentinel');
});

test('emergency revocation destroys the exact active secret even after signer trust withdrawal', function () {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture)->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verified-and-active');
    $receipt = (array) DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    config(['tenant_ai.verification.receipt_keys.offline-test.trusted' => false]);
    $emergency = TenantAiEmergencyContext::resolve($fixture['request'], $fixture['row']['workspace_public_id']);
    $result = (new TenantAiActiveLifecycle)->revoke($emergency, $fixture['connection'], $fixture['command']->credentialId, 3, 4);
    $credential = DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->firstOrFail();
    expect($result['state'])->toBe('revoked')->and($credential->secret_ciphertext)->toBeNull()->and($credential->wrapped_dek)->toBeNull()
        ->and($credential->kek_version)->toBeNull()->and($credential->secret_destroyed_at)->not->toBeNull()
        ->and(DB::table('tenant_ai_settings')->where('workspace_id', $fixture['row']['workspace_id'])->value('mode'))->toBe('disabled')
        ->and(DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->value('active_generation'))->toBe(2)
        ->and((array) DB::table('assistant_provider_attempts')->where('id', $receipt['id'])->firstOrFail())->toBe($receipt);
});

test('account retention denies deletion before and during A1 admission without a user root lock inversion', function () {
    $fixture = verifierFixture($this);
    expect(fn () => (new DeleteUserAccount)->execute($fixture['user']))->toThrow(BillingActionRefused::class);
    $results = quotaRace(function (int $worker) use ($fixture) {
        if ($worker === 1) {
            expect(fn () => (new DeleteUserAccount)->execute($fixture['user']))->toThrow(BillingActionRefused::class);

            return;
        }
        $context = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
        $result = offlineVerifier($fixture)->verify($context, $fixture['command']);
        if ($result->status !== 'verified-and-active') {
            throw new RuntimeException('Admission did not survive refused deletion');
        }
    }, 'independent');
    expect(array_column($results, 'result'))->toBe(['committed', 'committed'])
        ->and(DB::table('users')->where('id', $fixture['user']->id)->value('attribution_id'))->toBe($fixture['context']->actorAttributionId)
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('active');
});

test('an observation older than five seconds can finalize evidence but cannot promote', function () {
    $fixture = verifierFixture($this);
    $delayed = false;
    $result = offlineVerifier($fixture, function ($buffer, $fixture) use (&$delayed) {
        $buffer->header("HTTP/1.1 200 OK\r\n");
        $buffer->header("Content-Type: application/json\r\n");
        $buffer->header('anthropic-organization-id: '.$fixture['account']['organization_id']."\r\n");
        $buffer->header('anthropic-workspace-id: '.$fixture['account']['workspace_id']."\r\n");
        $buffer->header("\r\n");
        $buffer->chunk(json_encode(['type' => 'model', 'id' => $fixture['manifest']['model']]));
        DB::listen(function ($query) use (&$delayed) {
            if (! $delayed && $query->sql === 'SELECT clock_timestamp() AS instant') {
                $delayed = true;
                usleep(5500000);
            }
        });
    })->verify($fixture['context'], $fixture['command']);
    expect($delayed)->toBeTrue()->and($result->status)->toBe('credential-state-changed');
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($attempt->promotion_disposition)->toBe('expired')->and($attempt->receipt_mac)->not->toBeNull()
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
});

test('receipt signer custody denies wrong root symlink reused key and missing trust before admission', function (string $change) {
    $fixture = verifierFixture($this);
    $path = $fixture['root'].'/receipts/key';
    if ($change === 'symlink') {
        symlink($path, $fixture['root'].'/receipts/alias');
        config(['tenant_ai.verification.receipt_keys.offline-test.path' => $fixture['root'].'/receipts/alias']);
    } elseif ($change === 'application key') {
        config(['app.key' => 'base64:'.base64_encode(file_get_contents($path))]);
    } elseif ($change === 'KEK') {
        file_put_contents($path, file_get_contents($fixture['root'].'/keks/key'));
    } elseif ($change === 'root') {
        config(['tenant_ai.verification.receipt_key_root' => public_path()]);
    } else {
        config(['tenant_ai.verification.receipt_keys.offline-test.path' => $fixture['root'].'/receipts/missing']);
    }
    $called = false;
    $result = offlineVerifier($fixture, function () use (&$called) {
        $called = true;
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verification-unavailable')->and($called)->toBeFalse()
        ->and(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->exists())->toBeFalse();
})->with(['symlink', 'application key', 'KEK', 'root', 'missing']);

test('authenticated envelope tamper fails before provider bytes with liability retained', function () {
    $fixture = verifierFixture($this);
    DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->update(['wrap_revision' => 2, 'wrapped_dek' => 'synthetic-invalid-envelope']);
    $called = false;
    $result = offlineVerifier($fixture, function () use (&$called) {
        $called = true;
    })->verify($fixture['context'], $fixture['command']);
    expect($called)->toBeFalse()->and($result->status)->toBe('verification-unavailable');
    $attempt = DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->firstOrFail();
    expect($attempt->send_authorized_at)->toBeNull()->and($attempt->receipt_mac)->toBeNull()
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9)
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending');
});

test('authority expiring during transactional completion audits rolls back promotion', function () {
    $fixture = verifierFixture($this);
    $expiry = TenantAiVerificationAdmission::instant()->addMilliseconds(1200);
    DB::table('assistant_provider_controls')->where('budget_id', $fixture['row']['usage_budget_id'])->update([
        'approval_expires_at' => $expiry->format('Y-m-d H:i:s.uP'), 'revision' => 3]);
    DB::unprepared("CREATE FUNCTION verification_slow_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.event IN ('assistant.ai.verification_succeeded','assistant.ai.credential_promoted','assistant.ai.selection_changed') THEN PERFORM pg_sleep(0.8); END IF; RETURN NEW; END; \$\$");
    DB::unprepared('CREATE TRIGGER verification_slow_audit BEFORE INSERT ON activity_log FOR EACH ROW EXECUTE FUNCTION verification_slow_audit()');
    try {
        $result = offlineVerifier($fixture)->verify($fixture['context'], $fixture['command']);
    } finally {
        DB::unprepared('DROP TRIGGER verification_slow_audit ON activity_log');
        DB::unprepared('DROP FUNCTION verification_slow_audit()');
    }
    expect(TenantAiVerificationAdmission::instant()->greaterThan($expiry))->toBeTrue();
    expect($result->status)->toBe('verification-unavailable')
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending')
        ->and(DB::table('assistant_provider_attempts')->where('id', $result->operationId)->value('state'))->toBe('admitted')
        ->and(DB::table('assistant_provider_attempts')->where('id', $result->operationId)->value('receipt_mac'))->toBeNull();
});

test('recovery commits while an expired worker remains alive and late completion cannot overwrite it', function () {
    $fixture = verifierFixture($this);
    $path = sys_get_temp_dir().'/verification-late-worker-'.bin2hex(random_bytes(10));
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        $context = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
        $result = offlineVerifier($fixture, function () {
            usleep(32000000);
        })->verify($context, $fixture['command']);
        file_put_contents($path, json_encode(['status' => $result->status]));
        DB::disconnect();
        exit(0);
    }
    expect($pid)->toBeGreaterThan(0);
    try {
        DB::purge();
        $until = microtime(true) + 35;
        while (! DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])
            ->whereNotNull('send_authorized_at')->whereRaw("admitted_at < clock_timestamp() - interval '30 seconds'")->exists()) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Live worker recovery barrier expired');
            }
            usleep(100000);
        }
        expect(pcntl_waitpid($pid, $status, WNOHANG))->toBe(0);
        (new TenantAiVerificationRecovery)->recover();
        $before = (array) DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->firstOrFail();
        expect($before['promotion_disposition'])->toBe('abandoned')->and($before['receipt_mac'])->toBeNull();
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(json_decode(file_get_contents($path), true)['status'])->toBe('verification-unavailable')
            ->and((array) DB::table('assistant_provider_attempts')->where('id', $before['id'])->firstOrFail())->toBe($before)
            ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending')
            ->and(DB::table('assistant_provider_windows')->whereIn('id', DB::table('assistant_provider_allocations')->where('attempt_id', $before['id'])->select('window_id'))->sum('unknown_usage_count'))->toBe('9');
    } finally {
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('transport failures retain liability and never change credential authority', function (string $attack, string $outcome) {
    $fixture = verifierFixture($this);
    $result = offlineVerifier($fixture, function ($buffer) use ($attack): void {
        if ($attack === 'overflow') {
            $buffer->header("HTTP/1.1 200 OK\r\n");
            $buffer->header("\r\n");
            $buffer->chunk(str_repeat('x', 65537));
            throw new RuntimeException('synthetic discarded body');
        }
        if ($attack === '599') {
            $buffer->header("HTTP/1.1 599 Unavailable\r\n");
            $buffer->header("\r\n");

            return;
        }
        throw new RuntimeException('synthetic discarded socket error', match ($attack) {
            'connect' => CURLE_COULDNT_CONNECT,
            'timeout' => CURLE_OPERATION_TIMEDOUT,
            'tls' => CURLE_SSL_CACERT,
        });
    })->verify($fixture['context'], $fixture['command']);
    $attempt = DB::table('assistant_provider_attempts')->where('id', $result->operationId)->firstOrFail();
    expect($result->status)->toBe('verification-rejected')->and($attempt->verification_outcome)->toBe($outcome)
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $attempt->id)->count())->toBe(9)
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('state'))->toBe('pending')
        ->and(DB::table('tenant_ai_credentials')->where('id', $fixture['command']->credentialId)->value('verification_state'))->toBe('unverified')
        ->and($attempt->receipt_mac)->toBeNull();
})->with([['overflow', 'transport_policy_rejected'], ['599', 'provider_unavailable'], ['connect', 'provider_unavailable'],
    ['timeout', 'timeout'], ['tls', 'transport_policy_rejected']]);

test('sequential tenants and exception paths cannot reuse closed authority or secrets', function () {
    $a = verifierFixture($this);
    $configuration = config('tenant_ai');
    $sessionA = offlineVerifier($a);
    $first = $sessionA->verify($a['context'], $a['command']);
    expect($first->status)->toBe('verified-and-active');
    $aSnapshot = DB::table('tenant_ai_credentials')->where('connection_id', $a['connection'])->get()->toJson();
    $b = verifierFixture($this);
    expect(fn () => $a['context']->authorize())->toThrow(TenantAiStorageUnavailable::class);
    $sessionB = offlineVerifier($b, fn () => throw new RuntimeException('synthetic exception path'));
    expect($sessionB->verify($b['context'], $b['command'])->status)->toBe('verification-rejected');
    config(['tenant_ai' => $configuration]);
    app()->instance('request', $a['request']);
    auth('web')->login($a['user']);
    expect($a['context']->authorize()->workspaceId)->toBe($a['owner']->workspaceId)
        ->and(fn () => $sessionA->verify($a['context'], $a['command']))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => $sessionB->verify($a['context'], $a['command']))->toThrow(TenantAiStorageUnavailable::class)
        ->and(DB::table('tenant_ai_credentials')->where('connection_id', $a['connection'])->get()->toJson())->toBe($aSnapshot);
    foreach ([$sessionA, $sessionB] as $session) {
        foreach (['secret', 'requestPermit', 'promotionPermit', 'context', 'observation', 'policy'] as $property) {
            expect((new ReflectionProperty($session, $property))->getValue($session))->toBeNull();
        }
        expect(json_encode($session))->not->toContain($a['secret'], $b['secret']);
    }
});

test('a fork cannot reuse the real registered send permit', function () {
    $fixture = verifierFixture($this);
    $verifier = null;
    $verifier = offlineVerifier($fixture, null, function () use (&$verifier, $fixture): void {
        $permit = (new ReflectionProperty($verifier, 'requestPermit'))->getValue($verifier);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $verifier->authorizeSend($fixture['context'], $permit);
                exit(71);
            } catch (TenantAiStorageUnavailable) {
                exit(0);
            }
        }
        if ($pid < 0) {
            throw new RuntimeException('Fork unavailable');
        }
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
        DB::purge();
    });
    expect($verifier->verify($fixture['context'], $fixture['command'])->status)->toBe('verified-and-active');
});

test('each independent reservation ceiling rejects atomically before secret access', function (string $budgetRole) {
    $fixture = verifierFixture($this);
    $account = $fixture['row']['budget_id'];
    $budget = $fixture['row'][$budgetRole];
    $unit = $budgetRole === 'usage_budget_id' ? 'reserved_output_units' : 'reserved_micro_usd';
    $amount = $fixture['row'][$unit];
    config(['tenant_ai.verification.accounts.'.$account.'.limits.'.$budget.'.workspace_day.'.$unit => $amount - 1]);
    $before = DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson();
    $sent = false;
    $result = offlineVerifier($fixture, function () use (&$sent): void {
        $sent = true;
    })->verify($fixture['context'], $fixture['command']);
    expect($result->status)->toBe('verification-unavailable')->and($sent)->toBeFalse()
        ->and(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->exists())->toBeFalse()
        ->and(DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson())->toBe($before);
})->with(['usage_budget_id', 'aggregate_budget_id', 'budget_id']);

test('complete verifier admits only the fifth operation across two tenant deployment roots', function () {
    $a = verifierFixture($this);
    $a['configuration'] = config('tenant_ai');
    $b = verifierFixture($this, $a['user']);
    $b['configuration'] = config('tenant_ai');
    foreach (range(1, 4) as $index) {
        DB::transaction(function () use ($a): void {
            $history = verificationQuotaFixture($a['user']);
            $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            DB::table('assistant_provider_attempts')->insert([...$history['row'], 'admitted_at' => $time]);
            foreach ($history['allocations'] as $allocation) {
                DB::table('assistant_provider_allocations')->insert([...$allocation, 'created_at' => $time]);
            }
            DB::table('assistant_provider_attempts')->where('id', $history['attempt'])->update(['state' => 'failed',
                'verification_outcome' => 'local_policy_denied', 'promotion_disposition' => 'not_applicable',
                'outcome' => 'usage_unknown', 'finalized_at' => $time]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
    $results = quotaRace(function (int $worker) use ($a, $b): void {
        $fixture = $worker === 0 ? $a : $b;
        config(['tenant_ai' => $fixture['configuration']]);
        app()->instance('request', $fixture['request']);
        auth('web')->login($fixture['user']);
        $context = TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production');
        $result = offlineVerifier($fixture)->verify($context, $fixture['command']);
        if ($result->status !== 'verified-and-active') {
            throw new TenantAiStorageUnavailable;
        }
    });
    expect(array_column($results, 'result'))->toBe(['committed', 'quota_refused'])
        ->and(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $a['user']->attribution_id)->count())->toBe(5)
        ->and(DB::table('tenant_ai_credentials')->whereIn('connection_id', [$a['connection'], $b['connection']])->where('state', 'active')->count())->toBe(1);
});

test('context resolution and verifier preflight bound database read contention', function (string $boundary) {
    $fixture = verifierFixture($this);
    $directory = sys_get_temp_dir().'/verification-read-lock-'.bin2hex(random_bytes(10));
    mkdir($directory, 0700);
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        DB::transaction(function () use ($directory): void {
            DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            file_put_contents($directory.'/held', '1');
            $until = microtime(true) + 5;
            while (! file_exists($directory.'/release') && microtime(true) < $until) {
                usleep(1000);
            }
        });
        DB::disconnect();
        exit(0);
    }
    if ($pid < 0) {
        throw new RuntimeException('Fork unavailable');
    }
    try {
        quotaWait(fn () => file_exists($directory.'/held'));
        $started = hrtime(true);
        if ($boundary === 'context') {
            expect(fn () => TenantAiVerificationContext::resolve($fixture['request'], $fixture['row']['workspace_public_id'], $fixture['row']['legal_entity_public_id'], 'production'))
                ->toThrow(TenantAiStorageUnavailable::class);
        } else {
            expect(offlineVerifier($fixture)->verify($fixture['context'], $fixture['command'])->status)->toBe('verification-unavailable');
        }
        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.5)->and(DB::transactionLevel())->toBe(0);
    } finally {
        file_put_contents($directory.'/release', '1');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
    expect(DB::table('assistant_provider_attempts')->where('connection_id', $fixture['connection'])->exists())->toBeFalse();
})->with(['context', 'preflight']);
