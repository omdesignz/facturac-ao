<?php

use App\AgtEnvironment;
use App\Fiscal\AssistantExecutionGuard;
use App\Fiscal\AssistantInput;
use App\Fiscal\AssistantInteraction;
use App\Fiscal\AssistantInteractionContext;
use App\Fiscal\CustomerCapabilities;
use App\Fiscal\CustomerSearchCommand;
use App\Fiscal\CustomerSearchQuery;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\ExecutionContext;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');
require_once __DIR__.'/../AssistantFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Separate mandatory PostgreSQL assistant gate.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['assistant.enabled' => true]);
});

function assistantPgContext(array $f): AssistantInteractionContext
{
    $request = Request::create($f['url'], 'POST');
    $request->setUserResolver(fn () => User::findOrFail($f['user']->id));
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    $session = app('session')->driver();
    $session->start();
    $session->put('work_session_started_at', now()->getTimestamp());
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    app('auth')->guard('web')->setUser($f['user']);
    $request->setUserResolver(fn () => User::findOrFail($f['user']->id));

    return AssistantInteractionContext::resolve($request);
}

function assistantPgWait(string $path): void
{
    $until = microtime(true) + 10;
    while (! file_exists($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Missing process barrier');
        }usleep(1000);
    }
}

/** @return list<array<string, mixed>> */
function assistantPgProcesses(Closure $operation, int $workers = 2): array
{
    $dir = sys_get_temp_dir().'/phase6-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    DB::purge();
    $pids = [];
    try {
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$dir/ready-$i", 'ready');
                assistantPgWait("$dir/start");
                try {
                    $data = ['ok' => true, 'value' => $operation($i, $dir)];
                } catch (Throwable $e) {
                    $data = ['ok' => false, 'status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 503];
                }
                file_put_contents("$dir/result-$i", json_encode($data, JSON_THROW_ON_ERROR));
                exit(0);
            }
            if ($pid === -1) {
                throw new RuntimeException('Cannot create test process');
            }$pids[] = $pid;
        }
        foreach (array_keys($pids) as $i) {
            assistantPgWait("$dir/ready-$i");
        }file_put_contents("$dir/start", 'start');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(fn ($i) => json_decode(file_get_contents("$dir/result-$i"), true, flags: JSON_THROW_ON_ERROR), array_keys($pids));
    } finally {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$dir/*") as $path) {
            unlink($path);
        }rmdir($dir);
        DB::purge();
    }
}

function assistantPgSeed(array $f, int $count, int $offset = 0): void
{
    DB::insert("INSERT INTO customers (public_id,workspace_id,legal_entity_id,name,tax_identification_number,country_code,is_active,created_at,updated_at)
        SELECT '0000000000'||lpad((n+?)::text,16,'0'), ?, ?, 'Cliente '||n, '5'||lpad((n+?)::text,9,'0'), 'AO', true, now(), now()
        FROM generate_series(1,?) n", [$offset, $f['entity']->workspace_id, $f['entity']->id, $offset, $count]);
}

/** @return list<array<string, mixed>> */
function assistantPlanNodes(array $node): array
{
    $nodes = [$node];
    foreach ($node['Plans'] ?? [] as $child) {
        $nodes = [...$nodes, ...assistantPlanNodes($child)];
    }

    return $nodes;
}

/** @param array<string, mixed> $plan */
function assistantAssertCandidateTraversal(array $plan): int
{
    $candidates = array_values(array_filter(assistantPlanNodes($plan['Plan']), fn ($node) => ($node['Subplan Name'] ?? null) === 'CTE candidate_keys'));
    expect($candidates)->toHaveCount(1);
    $work = 0;
    foreach (assistantPlanNodes($candidates[0]) as $node) {
        expect($node['Temp Read Blocks'] ?? 0)->toBe(0)->and($node['Temp Written Blocks'] ?? 0)->toBe(0);
        if (($node['Relation Name'] ?? null) !== 'customers') {
            continue;
        }
        $inspected = (int) (($node['Actual Rows'] + ($node['Rows Removed by Filter'] ?? 0) + ($node['Rows Removed by Index Recheck'] ?? 0)) * $node['Actual Loops']);
        expect($inspected)->toBeLessThanOrEqual(10001);
        expect($node['Node Type'])->not->toBe('Seq Scan');
        $conditions = array_column(assistantPlanNodes($node), 'Index Cond');
        expect(implode(' ', $conditions))->toContain('workspace_id', 'legal_entity_id');
        expect($node['Rows Removed by Filter'] ?? 0)->toBe(0);
        expect($node['Lossy Heap Blocks'] ?? 0)->toBe(0);
        $work = max($work, $inspected);
    }

    return $work;
}

/** @param array<string, mixed> $local
 * @param  array<string, mixed>  $foreign
 */
function assistantPgSeedTenantOrder(array $local, array $foreign, int $localCount, int $foreignCount, string $ordering): void
{
    $isLocal = $ordering === 'newer_foreign' ? 'n <= ?' : 'CASE WHEN n <= 2 * LEAST(CAST(? AS BIGINT), CAST(? AS BIGINT)) THEN MOD(n, 2) = 1 ELSE CAST(? AS BIGINT) > CAST(? AS BIGINT) END';
    $orderBindings = $ordering === 'newer_foreign' ? [$localCount] : [$localCount, $foreignCount, $localCount, $foreignCount];
    DB::insert("WITH ordered_rows AS (SELECT n, $isLocal AS is_local FROM generate_series(1, ?) n)
        INSERT INTO customers (public_id, workspace_id, legal_entity_id, name, tax_identification_number, country_code, is_active, created_at, updated_at)
        SELECT '0000000000'||lpad((n+6000000)::text,16,'0'), CASE WHEN is_local THEN CAST(? AS BIGINT) ELSE CAST(? AS BIGINT) END,
            CASE WHEN is_local THEN CAST(? AS BIGINT) ELSE CAST(? AS BIGINT) END, 'Cliente '||n, '5'||lpad(n::text,9,'0'), 'AO', true, now(), now()
        FROM ordered_rows ORDER BY n", [...$orderBindings, $localCount + $foreignCount,
        $local['entity']->workspace_id, $foreign['entity']->workspace_id, $local['entity']->id, $foreign['entity']->id]);
}

test('postgres assistant candidate traversal stays scoped under adverse tenant ordering and capacity boundaries', function (string $ordering, int $localCount, int $foreignCount, bool $siblingEntity = false) {
    $local = assistantFixture();
    $foreign = assistantFixture();
    if ($siblingEntity) {
        $foreign['entity'] = LegalEntity::factory()->configured()->create(['workspace_id' => $local['entity']->workspace_id]);
    }
    DB::table('customers')->delete();
    assistantPgSeedTenantOrder($local, $foreign, $localCount, $foreignCount, $ordering);
    DB::statement('ANALYZE customers');
    $context = ExecutionContext::resolve($local['user'], $local['entity'], AgtEnvironment::Production, readOnly: true);
    $command = CustomerSearchCommand::fromInput('Cliente', 'all');
    $statement = CustomerSearchQuery::statement($context, $command);
    $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['bindings'], false)->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
    $work = assistantAssertCandidateTraversal($plan);
    expect($work)->toBe(min($localCount, 10001));
    foreach (assistantPlanNodes($plan['Plan']) as $node) {
        expect($node['Temp Read Blocks'] ?? 0)->toBe(0)->and($node['Temp Written Blocks'] ?? 0)->toBe(0);
    }
    if ($localCount > 10000) {
        try {
            AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, $command));
            test()->fail('Over-cap returned data');
        } catch (HttpExceptionInterface $error) {
            expect($error->getStatusCode())->toBe(503);
        }
        $matches = array_values(array_filter(assistantPlanNodes($plan['Plan']), fn ($node) => ($node['Subplan Name'] ?? null) === 'CTE matches'));
        expect($matches)->toHaveCount(1);
        foreach (assistantPlanNodes($matches[0]) as $node) {
            if (($node['Relation Name'] ?? null) === 'customers') {
                expect($node['Actual Loops'])->toBe(0);
            }
        }
    } else {
        $result = AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, $command));
        $expected = DB::table('customers')->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->legalEntityId)
            ->orderByDesc('id')->limit(10)->pluck('public_id')->all();
        expect(array_column($result['items'], 'public_id'))->toBe($expected)->and($result['has_more'])->toBe($localCount > 10);
    }
    $path = base_path('docs/phase-6-implementation-evidence.json');
    $evidence = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $key = "$ordering-$localCount-$foreignCount".($siblingEntity ? '-sibling_entity' : '');
    $evidence['customer_adversarial_plans'][$key] = ['candidate_rows_inspected' => $work, 'plan' => $plan];
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
})->with([
    ['newer_foreign', 200001, 20001], ['newer_foreign', 200001, 200001],
    ['interleaved', 200001, 20001], ['interleaved', 200001, 200001],
    ['newer_foreign', 9999, 20001], ['newer_foreign', 10000, 20001], ['newer_foreign', 10001, 20001],
    ['newer_foreign', 0, 200001], ['newer_foreign', 3, 200001],
    ['newer_foreign', 200001, 20001, true], ['interleaved', 10000, 20001, true],
]);

test('postgres assistant customer plans are bounded complete or fail closed across scoped capacity', function (int $count) {
    $f = assistantFixture();
    DB::table('customers')->delete();
    $outside = assistantFixture();
    assistantPgSeed($outside, 20001, 400000);
    assistantPgSeed($f, $count);
    DB::statement('ANALYZE customers');
    $context = ExecutionContext::resolve($f['user'], $f['entity'], AgtEnvironment::Production, readOnly: true);
    $command = CustomerSearchCommand::fromInput('Cliente', 'all');
    $statement = CustomerSearchQuery::statement($context, $command);
    $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['bindings'], false)->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
    $nodes = assistantPlanNodes($plan['Plan']);
    assistantAssertCandidateTraversal($plan);
    foreach ($nodes as $node) {
        expect($node['Temp Read Blocks'] ?? 0)->toBe(0)->and($node['Temp Written Blocks'] ?? 0)->toBe(0);
        if (($node['Relation Name'] ?? null) === 'customers') {
            expect(($node['Actual Rows'] ?? 0) * ($node['Actual Loops'] ?? 1))->toBeLessThanOrEqual(10001);
            expect(($node['Rows Removed by Filter'] ?? 0) * ($node['Actual Loops'] ?? 1))->toBeLessThanOrEqual(10001);
            expect($node['Node Type'])->not->toBe('Seq Scan');
        }
    }
    expect($plan['Execution Time'])->toBeLessThan(2000);
    $path = base_path('docs/phase-6-implementation-evidence.json');
    $evidence = file_exists($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    $evidence['customer_plans'][(string) $count] = $plan;
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($count > 10000) {
        try {
            AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, $command));
            test()->fail('Over-cap returned data');
        } catch (HttpExceptionInterface $e) {
            expect($e->getStatusCode())->toBe(503);
        }
        expect(Activity::where('event', 'customers.search')->count())->toBe(0);
    } else {
        $r = AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, $command));
        expect($r['items'])->toHaveCount(min($count, 10))->and($r['has_more'])->toBe($count > 10);
    }
})->with([0, 3, 10000, 10001, 200001]);

test('postgres assistant concurrent duplicate interactions cannot execute twice and locks recover', function (int $workers) {
    $f = assistantFixture();
    $body = assistantPayload($f);
    $results = assistantPgProcesses(function () use ($f, $body) {
        Context::flush();
        Context::add('request_id', (string) Str::uuid());
        assistantPlanner(function () {
            usleep(150000);

            return '{"decision":"unsupported","reason":"outside_read_contract"}';
        });

        return AssistantExecutionGuard::database(function () use ($f, $body) {
            $context = assistantPgContext($f);

            return app(AssistantInteraction::class)->execute($context, AssistantInput::fromJson(json_encode($body)), new AssistantExecutionGuard)['outcome'];
        });
    }, $workers);
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1);
    foreach ($results as $result) {
        if (! $result['ok']) {
            expect($result['status'])->toBeIn([409, 429]);
        }
    }
    expect(Activity::where('event', 'assistant.interaction.completed')->count())->toBe(1);
    $store = Cache::store('database')->getStore();
    $lock = $store->lock('assistant-user:'.$f['user']->id, 45);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with([2, 10]);

test('postgres assistant permission withdrawal committed during planning prevents every business read', function (string $change) {
    $f = assistantFixture();
    $r = assistantPgProcesses(function (int $i, string $dir) use ($f, $change) {
        if ($i === 1) {
            assistantPgWait("$dir/planning");
            match ($change) {
                'membership' => WorkspaceMembership::where('user_id', $f['user']->id)->update(['is_active' => false]),
                'mfa' => User::where('id', $f['user']->id)->update(['two_factor_secret' => null]),
                'role' => WorkspaceMembership::where('user_id', $f['user']->id)->update(['role' => 'viewer']),
            };
            file_put_contents("$dir/revoked", 'revoked');

            return 'revoked';
        }
        Context::flush();
        Context::add('request_id', (string) Str::uuid());
        assistantPlanner(function () use ($dir, $f, $change) {
            file_put_contents("$dir/planning", 'planning');
            assistantPgWait("$dir/revoked");

            return assistantReadPlan([['tool' => $change === 'role' ? 'getMonthlyRecordedBilling' : 'getCustomer', 'arguments' => $change === 'role' ? ['month' => '2024-02'] : ['public_id' => $f['customer']->public_id]]]);
        });

        return AssistantExecutionGuard::database(fn () => app(AssistantInteraction::class)->execute(assistantPgContext($f), AssistantInput::fromJson(json_encode(assistantPayload($f))), new AssistantExecutionGuard));
    });
    expect($r[0]['ok'])->toBeFalse()->and($r[0]['status'])->toBe(403)->and($r[1]['ok'])->toBeTrue();
    expect(Activity::where('event', 'assistant.tool.requested')->count())->toBe(0);
})->with(['membership', 'mfa', 'role']);

test('postgres assistant statement timeout failure restores both timeouts and primary connection usability', function () {
    $prior = DB::selectOne("SELECT current_setting('statement_timeout') s, current_setting('lock_timeout') l");
    try {
        AssistantExecutionGuard::database(fn () => DB::select('SELECT pg_sleep(3)'));
        test()->fail('Unbounded statement');
    } catch (QueryException) {
    }
    $after = DB::selectOne("SELECT current_setting('statement_timeout') s, current_setting('lock_timeout') l");
    expect($after->s)->toBe($prior->s)->and($after->l)->toBe($prior->l)->and(DB::selectOne('SELECT 1 AS n')->n)->toBe(1);
});

test('postgres assistant read snapshot never mixes uncommitted customer changes or rollback', function (bool $commit, string $change) {
    $f = assistantFixture();
    DB::table('fiscal_documents')->delete();
    $r = assistantPgProcesses(function (int $i, string $dir) use ($f, $commit, $change) {
        if ($i === 1) {
            DB::beginTransaction();
            match ($change) {
                'update' => DB::table('customers')->where('id', $f['customer']->id)->update(['name' => 'CHANGED']),
                'delete' => DB::table('customers')->where('id', $f['customer']->id)->delete(),
                'insert' => assistantPgSeed($f, 1, 900000),
            };
            file_put_contents("$dir/changed", 'changed');
            assistantPgWait("$dir/read");
            $commit ? DB::commit() : DB::rollBack();

            return 'writer';
        }
        assistantPgWait("$dir/changed");
        $context = ExecutionContext::resolve(User::findOrFail($f['user']->id), $f['entity'], AgtEnvironment::Production, readOnly: true);
        $result = AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, CustomerSearchCommand::fromInput('Cliente', 'all')));
        file_put_contents("$dir/read", 'read');

        return $result;
    });
    expect($r[0]['ok'])->toBeTrue()->and($r[0]['value']['items'][0]['name'])->toBe('Cliente Consulta');
    expect(DB::table('customers')->where('workspace_id', $f['entity']->workspace_id)->count())->toBe($commit ? match ($change) {
        'delete' => 0, 'insert' => 2, default => 1
    } : 1);
    if ($change === 'update') {
        expect($f['customer']->fresh()->name)->toBe($commit ? 'CHANGED' : 'Cliente Consulta');
    }
})->with([[true, 'update'], [false, 'update'], [true, 'insert'], [false, 'insert'], [true, 'delete'], [false, 'delete']]);

test('postgres assistant additive migration round trip preserves populated customer data and constraints', function () {
    $f = assistantFixture();
    $before = $f['customer']->fresh()->getAttributes();
    $migration = require base_path('database/migrations/2026_10_08_132807_add_assistant_context_index_to_customers.php');
    $migration->down();
    expect(DB::selectOne("SELECT to_regclass('customers_assistant_context_id_index') AS index")->index)->toBeNull();
    $migration->up();
    expect(DB::selectOne("SELECT to_regclass('customers_assistant_context_id_index') AS index")->index)->not->toBeNull();
    expect($f['customer']->fresh()->getAttributes())->toBe($before);
});

test('postgres assistant sparse names still inspect only the admitted payload and never other contexts', function () {
    $f = assistantFixture();
    DB::table('customers')->delete();
    $other = assistantFixture();
    assistantPgSeed($other, 50000, 400000);
    assistantPgSeed($f, 10000);
    $context = ExecutionContext::resolve($f['user'], $f['entity'], AgtEnvironment::Production, readOnly: true);
    $s = CustomerSearchQuery::statement($context, CustomerSearchCommand::fromInput('ABSENT', 'inactive'));
    DB::statement('ANALYZE customers');
    $p = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$s['sql'], $s['bindings'], false)->{'QUERY PLAN'}, true)[0];
    assistantAssertCandidateTraversal($p);
    foreach (assistantPlanNodes($p['Plan']) as $node) {
        expect($node['Temp Read Blocks'] ?? 0)->toBe(0)->and($node['Temp Written Blocks'] ?? 0)->toBe(0);
        if (($node['Relation Name'] ?? null) === 'customers') {
            expect($node['Node Type'])->not->toBe('Seq Scan');
            expect(($node['Actual Rows'] ?? 0) * ($node['Actual Loops'] ?? 1))->toBeLessThanOrEqual(10001);
        }
    }
    $result = AssistantExecutionGuard::database(fn () => app(CustomerCapabilities::class)->searchCustomers($context, CustomerSearchCommand::fromInput('ABSENT', 'inactive')));
    expect($result)->toBe(['items' => [], 'has_more' => false]);
    $path = base_path('docs/phase-6-implementation-evidence.json');
    $e = json_decode(file_get_contents($path), true);
    $e['customer_plans']['sparse_payload'] = $p;
    file_put_contents($path, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

test('postgres assistant scoped document and projection queries preserve primary unique lookup bounds', function () {
    $f = assistantFixture();
    $other = assistantFixture();
    $context = ExecutionContext::resolve($f['user'], $f['entity'], AgtEnvironment::Production, readOnly: true);
    $statements = [];
    $observe = true;
    DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$statements, &$observe) {
        if ($observe && str_starts_with(strtolower($sql), 'select') && (str_contains($sql, 'fiscal_documents') || str_contains($sql, 'agt_submissions'))) {
            $statements[] = ['sql' => $sql, 'bindings' => $bindings];
        }
    });
    AssistantExecutionGuard::database(function () use ($context, $f) {
        $cap = app(DocumentCapabilities::class);
        $cmd = DocumentReadCommand::fromPublicId($f['document']->public_id);
        $cap->readDocument($context, $cmd);
        $cap->readQualifiedAgtStatus($context, $cmd);
    });
    $observe = false;
    expect($statements)->toHaveCount(3);
    $plans = [];
    foreach ($statements as $s) {
        $p = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$s['sql'], $s['bindings'], false)->{'QUERY PLAN'}, true)[0];
        foreach (assistantPlanNodes($p['Plan']) as $n) {
            expect($n['Actual Rows'] ?? 0)->toBeLessThanOrEqual(1)->and($n['Temp Written Blocks'] ?? 0)->toBe(0);
        }$plans[] = $p;
    }
    $path = base_path('docs/phase-6-implementation-evidence.json');
    $e = json_decode(file_get_contents($path), true);
    $e['document_plans'] = $plans;
    file_put_contents($path, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

test('postgres assistant expired lock ownership cannot release a successor', function () {
    $f = assistantFixture();
    $store = Cache::store('database')->getStore();
    $key = 'assistant-user:'.$f['user']->id;
    $old = $store->lock($key, 45);
    expect($old->get())->toBeTrue();
    DB::table('cache_locks')->where('key', $store->getPrefix().$key)->update(['expiration' => time() - 1]);
    $new = $store->lock($key, 45);
    expect($new->get())->toBeTrue()->and($old->release())->toBeFalse();
    $third = $store->lock($key, 45);
    expect($third->get())->toBeFalse();
    $new->release();
    expect($third->get())->toBeTrue();
    $third->release();
});

test('postgres assistant killed worker retains replay rejection and successor recovers only after lease expiry', function () {
    $f = assistantFixture();
    $body = assistantPayload($f);
    $signal = sys_get_temp_dir().'/phase6-worker-'.bin2hex(random_bytes(8));
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        assistantPlanner(function () use ($signal, $f) {
            file_put_contents($signal, 'admitted');
            sleep(20);

            return assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]);
        });
        AssistantExecutionGuard::database(fn () => app(AssistantInteraction::class)->execute(assistantPgContext($f), AssistantInput::fromJson(json_encode($body)), new AssistantExecutionGuard));
        exit(0);
    }
    expect($pid)->toBeGreaterThan(0);
    try {
        assistantPgWait($signal);
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifsignaled($status))->toBeTrue();
        DB::purge();
        $store = Cache::store('database')->getStore();
        $key = 'assistant-user:'.$f['user']->id;
        expect($store->lock($key, 45)->get())->toBeFalse();
        DB::table('cache_locks')->where('key', $store->getPrefix().$key)->update(['expiration' => time() - 1]);
        assistantPlanner(assistantReadPlan([['tool' => 'getCustomer', 'arguments' => ['public_id' => $f['customer']->public_id]]]));
        try {
            AssistantExecutionGuard::database(fn () => app(AssistantInteraction::class)->execute(assistantPgContext($f), AssistantInput::fromJson(json_encode($body)), new AssistantExecutionGuard));
            test()->fail('Killed worker nonce replayed');
        } catch (HttpExceptionInterface $e) {
            expect($e->getStatusCode())->toBe(409);
        }
        $body['request_nonce'] = (string) Str::uuid();
        $response = AssistantExecutionGuard::database(fn () => app(AssistantInteraction::class)->execute(assistantPgContext($f), AssistantInput::fromJson(json_encode($body)), new AssistantExecutionGuard));
        expect($response['outcome'])->toBe('answered')->and(Activity::where('event', 'assistant.interaction.completed')->count())->toBe(1);
    } finally {
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        @unlink($signal);
        DB::purge();
    }
});
