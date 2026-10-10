<?php

use App\Fiscal\AssistantExecutionGuard;
use App\Fiscal\AssistantIntentGateway;
use App\Fiscal\AssistantInteraction;
use App\Fiscal\AssistantProviderInvocation;
use App\Fiscal\AssistantProviderLedger;
use App\Fiscal\AssistantProviderPermit;
use App\Fiscal\AssistantProviderProfile;
use App\Fiscal\AssistantProviderTransport;
use App\Fiscal\TenantAiLegacyAccounting;
use App\Models\Customer;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');
require_once __DIR__.'/../AssistantProviderFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory separate PostgreSQL provider accounting gate.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['assistant.enabled' => true]);
    $this->secret = tempnam(sys_get_temp_dir(), 'phase7-pg-synthetic-');
    file_put_contents($this->secret, 'synthetic-not-a-real-provider-key');
    chmod($this->secret, 0600);
});

afterEach(function () {
    if (isset($this->secret)) {
        @unlink($this->secret);
    }
    $this->travelBack();
});

function providerPgWait(string $path): void
{
    $deadline = hrtime(true) / 1e9 + 10;
    while (! file_exists($path)) {
        if (hrtime(true) / 1e9 > $deadline) {
            throw new RuntimeException('Provider process barrier expired');
        }
        usleep(1000);
    }
}

/** @return list<array<string, mixed>> */
function providerPgRace(Closure $operation): array
{
    $directory = sys_get_temp_dir().'/phase7-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $children = [];
    try {
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$directory/ready-$worker", 'ready');
                providerPgWait("$directory/start");
                try {
                    $result = ['ok' => true, 'value' => $operation($worker, $directory)];
                } catch (Throwable $error) {
                    $result = ['ok' => false, 'status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503];
                }
                file_put_contents("$directory/result-$worker", json_encode($result));
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Cannot fork provider test');
            }
            $children[] = $pid;
        }
        foreach (array_keys($children) as $worker) {
            providerPgWait("$directory/ready-$worker");
        }
        file_put_contents("$directory/start", 'go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(fn (int $worker): array => json_decode(file_get_contents("$directory/result-$worker"), true), [0, 1]);
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$directory/*") as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::purge();
    }
}

function providerPgReserve(AssistantProviderInvocation $invocation): string
{
    return AssistantExecutionGuard::database(fn (): string => AssistantProviderPermit::admit($invocation, app(AssistantIntentGateway::class), app(AssistantProviderLedger::class))->attemptId);
}

test('postgres provider final affordable reservation never overspends any scope under independent processes', function (string $scope) {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    config(['assistant.provider.budgets.'.$scope => 552816]);
    $results = providerPgRace(fn (): string => providerPgReserve(providerTestInvocation($f)));
    expect(count(array_filter($results, fn ($result) => $result['ok'])))->toBe(1);
    expect(DB::table('assistant_provider_attempts')->count())->toBe(1);
    expect(DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->count())->toBe(5);
    foreach (DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->get() as $window) {
        expect((int) $window->reserved_micro_usd)->toBe(552816)->and((int) $window->attempt_count)->toBe(1);
    }
})->with(['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day']);

test('postgres provider unique window creation and duplicate interaction accounting are atomic', function (bool $duplicate) {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $invocation = providerTestInvocation($f);
    $results = providerPgRace(fn (): string => providerPgReserve($duplicate ? $invocation : providerTestInvocation($f)));
    $count = $duplicate ? 1 : 2;
    expect(count(array_filter($results, fn ($result) => $result['ok'])))->toBe($count);
    expect(DB::table('assistant_provider_attempts')->count())->toBe($count);
    expect(DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->count())->toBe(5);
    foreach (DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->get() as $window) {
        expect((int) $window->reserved_micro_usd)->toBe(552816 * $count)->and((int) $window->attempt_count)->toBe($count);
    }
})->with([false, true]);

test('postgres concurrent provider finalization cannot double add usage or release reservations', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $id = providerPgReserve(providerTestInvocation($f));
    $results = providerPgRace(function () use ($id): void {
        app(AssistantProviderLedger::class)->finalize($id, ['input' => 10, 'output' => 5], true, false);
    });
    expect(array_column($results, 'ok'))->toBe([true, true]);
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('actual_micro_usd'))->toBe(25);
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
    expect(DB::table('activity_log')->where('event', 'assistant.provider.received')->count())->toBe(1);
});

test('postgres provider finalized usage remains in original UTC windows across month rollover', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T23:59:59Z'));
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $usageBudgetId = config('tenant_ai.legacy_accounting.'.config('assistant.provider.budget_id').'.usage_budget_id');
    expect(DB::table('assistant_provider_controls')->where('budget_id', $usageBudgetId)
        ->update(['approval_expires_at' => now()->addDay(), 'revision' => DB::raw('revision + 1')]))->toBe(1);
    $id = providerPgReserve(providerTestInvocation($f));
    $this->travel(2)->seconds();
    app(AssistantProviderLedger::class)->finalize($id, ['input' => 10, 'output' => 5], true, false);
    providerPgReserve(providerTestInvocation($f));
    expect(DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->count())->toBe(10);
    $old = DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->where('window_start', '<', '2026-11-01T00:00:00Z');
    expect((int) $old->sum('actual_micro_usd'))->toBe(25)->and((int) $old->sum('reserved_micro_usd'))->toBe(5 * 552816);
});

test('postgres provider database guards preserve immutable context terminal outcomes and liability', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $id = providerPgReserve(providerTestInvocation($f));
    expect(fn () => DB::transaction(fn () => DB::table('assistant_provider_attempts')->where('id', $id)->update(['actor_attribution_id' => (string) Str::uuid()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->update(['reserved_micro_usd' => 0])))->toThrow(QueryException::class);
    app(AssistantProviderLedger::class)->finalize($id, ['input' => 10, 'output' => 5], true, false);
    expect(fn () => DB::transaction(fn () => DB::table('assistant_provider_attempts')->where('id', $id)->update(['state' => 'admitted'])))->toThrow(QueryException::class);
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
});

test('postgres killed provider worker cannot lose admitted liability before or after transmission intent', function (bool $afterTransmission) {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $invocation = providerTestInvocation($f);
    $transport = new SyntheticIntentTransport;
    $transport->during = fn () => posix_kill(getmypid(), SIGKILL);
    app()->instance(AssistantProviderTransport::class, $transport);
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        if ($afterTransmission) {
            AssistantExecutionGuard::database(fn () => app(AssistantInteraction::class)->execute($invocation->context, $invocation->input, $invocation->guard));
        } else {
            providerPgReserve($invocation);
            posix_kill(getmypid(), SIGKILL);
        }
        exit(7);
    }
    expect($pid)->toBeGreaterThan(0);
    pcntl_waitpid($pid, $status);
    expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
    DB::purge();
    expect(DB::table('assistant_provider_attempts')->value('state'))->toBe('admitted');
    $this->travel(3)->minutes();
    expect(app(AssistantProviderLedger::class)->recover())->toBe(1);
    expect(DB::table('assistant_provider_attempts')->value('state'))->toBe('usage_unknown');
    expect((bool) DB::table('assistant_provider_controls')->where('contract_version', 'legacy')->value('circuit_blocked'))->toBeTrue();
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
    expect(app(AssistantProviderLedger::class)->recover())->toBe(0);
})->with([false, true]);

test('postgres committed approval withdrawal before transmission cannot use an admitted permit', function (string $change) {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $results = providerPgRace(function (int $worker, string $directory) use ($f, $change): array {
        if ($worker === 1) {
            providerPgWait("$directory/reserved");
            if ($change === 'circuit') {
                DB::transaction(function (): void {
                    TenantAiLegacyAccounting::lock(config('assistant.provider.budget_id'));
                    DB::table('assistant_provider_controls')->where('budget_id', config('assistant.provider.budget_id'))
                        ->update(['circuit_blocked' => true, 'revision' => DB::raw('revision + 1')]);
                });
            } else {
                DB::table('assistant_provider_acknowledgements')->update(['revoked_at' => now()]);
            }
            file_put_contents("$directory/withdrawn", 'committed');

            return ['changed' => true];
        }
        $invocation = providerTestInvocation($f);
        $transport = new SyntheticIntentTransport;
        $ledger = app(AssistantProviderLedger::class);
        $gateway = new AssistantIntentGateway($transport, $ledger);
        $permit = AssistantProviderPermit::admit($invocation, $gateway, $ledger);
        file_put_contents("$directory/reserved", 'ready');
        providerPgWait("$directory/withdrawn");
        try {
            $gateway->execute($invocation, $permit, 'synthetic-not-a-real-provider-key');

            return ['status' => 200, 'sent' => count($transport->bodies)];
        } catch (HttpExceptionInterface $error) {
            return ['status' => $error->getStatusCode(), 'sent' => count($transport->bodies)];
        }
    });
    expect($results[0]['value'])->toBe(['status' => 503, 'sent' => 0]);
    expect(DB::table('assistant_provider_attempts')->value('outcome'))->toBe('not_sent');
    expect(DB::table('assistant_provider_attempts')->value('input_tokens'))->toBeNull();
    expect((bool) DB::table('assistant_provider_controls')->where('contract_version', 'legacy')->value('circuit_blocked'))->toBe($change === 'circuit');
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
})->with(['circuit', 'acknowledgement']);

test('postgres provider user budgets span explicit workspaces and ignore mutable browser selection', function () {
    $f = assistantFixture();
    $other = assistantFixture();
    providerApprovals($f, $this->secret);
    DB::table('workspace_memberships')->insert(['workspace_id' => $other['entity']->workspace_id, 'user_id' => $f['user']->id, 'role' => 'viewer', 'is_active' => true, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('assistant_provider_tenants')->insert(['workspace_id' => $other['entity']->workspace_id, 'profile' => AssistantProviderProfile::ID,
        'policy' => AssistantProviderProfile::POLICY, 'owner_attribution_id' => $other['user']->attribution_id,
        'approval_reference' => 'synthetic-other-tenant', 'expires_at' => now()->addDay()]);
    DB::table('assistant_provider_acknowledgements')->insert(['workspace_id' => $other['entity']->workspace_id,
        'actor_attribution_id' => $f['user']->attribution_id, 'policy' => AssistantProviderProfile::POLICY, 'acknowledged_at' => now()]);
    $other['user'] = $f['user'];
    config(['assistant.provider.budgets.user_day' => 552816]);
    providerPgReserve(providerTestInvocation($f));
    try {
        providerPgReserve(providerTestInvocation($other));
        $this->fail('The same actor must not obtain a fresh user budget in another workspace.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(429);
    }
    expect(DB::table('assistant_provider_attempts')->count())->toBe(1);
    expect(DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->where('scope', 'user_day')->count())->toBe(1);
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->where('scope', 'user_day')->value('reserved_micro_usd'))->toBe(552816);
});

test('postgres provider additive migration round trip preserves existing domain records', function () {
    $f = assistantFixture();
    $successors = array_map(fn (string $name) => require database_path('migrations/'.$name), [
        '2026_10_09_020000_extend_ai_verification_metadata.php',
        '2026_10_09_020100_install_ai_verification_integrity.php',
        '2026_10_09_020200_validate_ai_verification_integrity.php',
    ]);
    foreach (array_reverse($successors) as $successor) {
        $successor->down();
    }
    $storage = require database_path('migrations/2026_10_09_010739_create_tenant_ai_storage_tables.php');
    $catalogue = require database_path('migrations/2026_10_09_011029_seed_tenant_ai_storage_catalogue.php');
    $catalogue->down();
    $storage->down();
    $migration = require database_path('migrations/2026_10_08_183139_create_assistant_provider_metadata_tables.php');
    $migration->down();
    expect(Customer::find($f['customer']->id)->name)->toBe('Cliente Consulta');
    $migration->up();
    expect(DB::table('assistant_provider_controls')->count())->toBe(0);
    expect(DB::table('assistant_provider_tenants')->count())->toBe(0);
    expect(FiscalDocument::find($f['document']->id))->not->toBeNull();
    $storage->up();
    $catalogue->up();
    expect(DB::table('tenant_ai_settings')->count())->toBe(0);
    foreach ($successors as $successor) {
        $successor->up();
    }
});

test('postgres provider budget and unresolved liability survive connection restart and cache loss', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    config(['assistant.provider.budgets.user_day' => 552816]);
    $id = providerPgReserve(providerTestInvocation($f));
    DB::table('cache')->delete();
    DB::purge();
    try {
        providerPgReserve(providerTestInvocation($f));
        $this->fail('Restart and cache loss must not restore spent admission capacity.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(429);
    }
    expect(DB::table('assistant_provider_attempts')->value('id'))->toBe($id);
    expect(DB::table('assistant_provider_attempts')->value('state'))->toBe('admitted');
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
    config(['assistant.provider.profile' => 'unreviewed-new-profile']);
    try {
        providerPgReserve(providerTestInvocation($f));
        $this->fail('A different unreviewed profile must fail closed.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(503);
    }
    expect(DB::table('assistant_provider_attempts')->count())->toBe(1);
});

test('postgres worker death after received commit cannot cause usage to be counted twice', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $id = providerPgReserve(providerTestInvocation($f));
    DB::disconnect();
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);
    if ($pid === 0) {
        DB::purge();
        app(AssistantProviderLedger::class)->finalize($id, ['input' => 10, 'output' => 5], true, false);
        posix_kill(getmypid(), SIGKILL);
        exit(99);
    }
    pcntl_waitpid($pid, $status);
    expect(pcntl_wifsignaled($status))->toBeTrue();
    DB::purge();
    app(AssistantProviderLedger::class)->finalize($id, ['input' => 10, 'output' => 5], true, false);
    expect(DB::table('assistant_provider_attempts')->value('state'))->toBe('received');
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('actual_micro_usd'))->toBe(25);
    expect((int) DB::table('assistant_provider_windows')->where('budget_id', config('assistant.provider.budget_id'))->sum('reserved_micro_usd'))->toBe(5 * 552816);
    expect(DB::table('activity_log')->where('event', 'assistant.provider.received')->count())->toBe(1);
});

test('legacy reservations share usage liability and root emergency denies later send authorization', function () {
    $f = assistantFixture();
    providerApprovals($f, $this->secret);
    $invocation = providerTestInvocation($f);
    $id = providerPgReserve($invocation);
    $mapping = config('tenant_ai.legacy_accounting.'.config('assistant.provider.budget_id'));
    $windows = DB::table('assistant_provider_windows')->where('budget_id', $mapping['usage_budget_id']);
    expect($windows->count())->toBe(5)->and((int) $windows->sum('reserved_attempt_units'))->toBe(5)
        ->and((int) $windows->sum('reserved_output_units'))->toBe(5 * AssistantProviderProfile::OUTPUT_LIMIT)
        ->and((int) $windows->sum('reserved_micro_usd'))->toBe(0);
    DB::table('ai_gateway_controls')->where('deployment_id', $mapping['deployment_id'])->where('kind', 'global')->update(['circuit_blocked' => true, 'revision' => 2]);
    expect(fn () => app(AssistantProviderLedger::class)->gates($invocation))->toThrow(HttpException::class);
    app(AssistantProviderLedger::class)->finalize($id, null, false, false);
    expect((int) $windows->sum('unknown_usage_count'))->toBe(5)
        ->and((int) $windows->sum('reserved_attempt_units'))->toBe(5);
});
