<?php

use App\Actions\DeleteUserAccount;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiCatalogue;
use App\Fiscal\TenantAiLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');
require_once __DIR__.'/../TenantAiLifecycleFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('PostgreSQL lifecycle gate required.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    tenantAiLifecycleSetup($this);
});
afterEach(function () {
    tenantAiLifecycleCleanup($this);
    RefreshDatabaseState::$migrated = false;
});

function lifecycleBarrier(Closure $condition): void
{
    $deadline = microtime(true) + 30;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Lifecycle process barrier expired');
        }
        usleep(1000);
    }
}

/** Hold the first worker at its real root lock; prove the other backend waits on a DB lock before releasing it.
 * @param  Closure(int): void  $operation
 * @return list<array{ok: bool, error: ?string, locks: list<string>}>
 */
function lifecyclePgRace(Closure $operation, bool $retentionDeniesBeforeLock = false): array
{
    $directory = sys_get_temp_dir().'/lifecycle-race-'.Str::uuid();
    mkdir($directory, 0700);
    DB::purge();
    $children = [];
    try {
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                $locks = [];
                try {
                    DB::statement("SET statement_timeout='25s'");
                    file_put_contents("$directory/pid-$worker", (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    if ($worker === 1) {
                        lifecycleBarrier(fn () => file_exists("$directory/held"));
                    }
                    DB::listen(function ($query) use (&$locks, $worker, $directory) {
                        if (str_contains(strtolower($query->sql), 'for update')) {
                            preg_match('/from "([a-z_]+)"/', $query->sql, $matches);
                            $locks[] = $matches[1] ?? 'unknown';
                            if ($worker === 0 && count($locks) === 1) {
                                file_put_contents("$directory/held", 'held');
                                lifecycleBarrier(fn () => file_exists("$directory/release"));
                            }
                        }
                    });
                    $operation($worker);
                    $result = ['ok' => true, 'error' => null, 'locks' => $locks];
                } catch (Throwable $error) {
                    $result = ['ok' => false, 'error' => $error::class, 'locks' => $locks];
                }
                file_put_contents("$directory/result-$worker", json_encode($result));
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Cannot fork lifecycle worker');
            }
            $children[] = $pid;
        }
        lifecycleBarrier(fn () => file_exists("$directory/held") && file_exists("$directory/pid-1"));
        $secondPid = (int) file_get_contents("$directory/pid-1");
        if ($retentionDeniesBeforeLock) {
            lifecycleBarrier(fn () => file_exists("$directory/result-1"));
            $early = json_decode(file_get_contents("$directory/result-1"), true);
            expect($early)->toBe(['ok' => false, 'error' => TenantAiStorageUnavailable::class, 'locks' => []]);
        } else {
            lifecycleBarrier(fn () => (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock') AS waiting", [$secondPid])->waiting);
        }
        file_put_contents("$directory/release", 'go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($worker) => json_decode(file_get_contents("$directory/result-$worker"), true), [0, 1]);
        $order = ['ai_gateway_controls' => 0, 'tenant_ai_settings' => 1, 'tenant_ai_connections' => 2, 'tenant_ai_credentials' => 3];
        foreach ($results as $result) {
            if ($retentionDeniesBeforeLock && $result['locks'] === []) {
                continue;
            }
            $ranks = array_map(fn ($table) => $order[$table] ?? -1, $result['locks']);
            $sorted = $ranks;
            sort($sorted);
            expect($ranks)->toBe($sorted)->and($ranks[0] ?? null)->toBe(0)
                ->and(count(array_filter($ranks, fn ($rank) => $rank === 0)))->toBe(1);
            if (! $result['ok']) {
                expect($result['error'])->toBe(TenantAiStorageUnavailable::class);
            }
        }

        return $results;
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::purge();
    }
}

test('postgres pending replacements serialize with exactly one new generation and coherent audit', function () {
    $f = tenantAiLifecycleFixture();
    $result = lifecyclePgRace(fn ($worker) => app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-worker-'.$worker));
    expect(array_column($result, 'ok'))->toBe([true, false])
        ->and(DB::table('tenant_ai_credentials')->where('state', 'pending')->count())->toBe(1)
        ->and(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(0)
        ->and((int) DB::table('tenant_ai_credentials')->where('state', 'pending')->value('version_number'))->toBe(2)
        ->and(DB::table('tenant_ai_credentials')->where('state', 'revoked')->value('secret_ciphertext'))->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_revoked')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_destroyed')->count())->toBe(1);
});

test('postgres pending replacement versus exact revocation honors the first serialized decision', function (bool $replaceFirst) {
    $f = tenantAiLifecycleFixture();
    $result = lifecyclePgRace(function ($worker) use ($f, $replaceFirst) {
        $l = app(TenantAiLifecycle::class);
        if (($worker === 0) === $replaceFirst) {
            $l->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-next');
        } else {
            $l->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1);
        }
    });
    expect(array_column($result, 'ok'))->toBe([true, false])
        ->and(DB::table('tenant_ai_credentials')->where('state', 'pending')->count())->toBe($replaceFirst ? 1 : 0)
        ->and(DB::table('tenant_ai_credentials')->where('id', $f['metadata']['id'])->value('secret_ciphertext'))->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_revoked')->count())->toBe(1);
})->with([true, false]);

test('postgres duplicate revocations commit one destruction and stale replay cannot resurrect it', function () {
    $f = tenantAiLifecycleFixture();
    $result = lifecyclePgRace(fn () => app(TenantAiLifecycle::class)->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1));
    expect(array_column($result, 'ok'))->toBe([true, false])
        ->and(DB::table('tenant_ai_credentials')->value('state'))->toBe('revoked')
        ->and(DB::table('tenant_ai_credentials')->value('wrapped_dek'))->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_destroyed')->count())->toBe(1);
    $before = tenantAiLifecycleSnapshot();
    app(TenantAiLifecycle::class)->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 2);
    expect(tenantAiLifecycleSnapshot())->toBe($before);
});

test('postgres disable and pending replacement preserve disabled policy under either lock ordering', function (bool $disableFirst, string $target) {
    $f = tenantAiLifecycleFixture();
    if ($target === 'workspace') {
        DB::table('tenant_ai_settings')->update(['mode' => 'vap_managed', 'profile_id' => TenantAiCatalogue::ID, 'revision' => 2]);
    } else {
        DB::table('tenant_ai_connections')->update(['disabled' => false, 'revision' => 2]);
    }
    $settingsRevision = $target === 'workspace' ? 2 : 1;
    $connectionRevision = $target === 'connection' ? 2 : 1;
    $result = lifecyclePgRace(function ($worker) use ($f, $target, $disableFirst, $settingsRevision, $connectionRevision) {
        $l = app(TenantAiLifecycle::class);
        if (($worker === 0) === $disableFirst) {
            if ($target === 'workspace') {
                $l->disableWorkspace($f['emergency'], $settingsRevision);
            } else {
                $l->disableConnection($f['emergency'], $f['metadata']['connection_id'], $settingsRevision, $connectionRevision);
            }
        } else {
            $l->candidate($f['context'], $f['metadata']['connection_id'], $settingsRevision, $connectionRevision, $f['metadata']['id'], 'synthetic-next');
        }
    });
    $bothSucceed = $target === 'workspace' && ! $disableFirst;
    expect(array_column($result, 'ok'))->toBe([true, $bothSucceed]);
    if ($target === 'connection' && ! $disableFirst) {
        expect((bool) DB::table('tenant_ai_connections')->value('disabled'))->toBeFalse();
        app(TenantAiLifecycle::class)->disableConnection($f['emergency'], $f['metadata']['connection_id'], 1, 3);
    }
    expect(DB::table('tenant_ai_settings')->value('mode'))->toBe('disabled')
        ->and((bool) DB::table('tenant_ai_connections')->value('disabled'))->toBeTrue()
        ->and(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(0)
        ->and(DB::table('tenant_ai_credentials')->where('state', 'pending')->count())->toBe(1);
})->with([true, false])->with(['workspace', 'connection']);

test('postgres actual database failures roll back destruction insertion revisions and audit', function (string $point) {
    $f = tenantAiLifecycleFixture();
    $before = tenantAiLifecycleSnapshot();
    [$table, $event, $when] = match ($point) {
        'destroy' => ['tenant_ai_credentials', 'UPDATE', "NEW.state='revoked'"],
        'insert' => ['tenant_ai_credentials', 'INSERT', 'NEW.version_number=2'],
        'revision' => ['tenant_ai_connections', 'UPDATE', 'NEW.revision=2'],
        'audit' => ['activity_log', 'INSERT', "NEW.event='assistant.ai.credential_configured'"],
    };
    DB::statement("CREATE OR REPLACE FUNCTION lifecycle_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic lifecycle failure'; END; \$\$");
    DB::statement("CREATE TRIGGER lifecycle_failure AFTER $event ON $table FOR EACH ROW WHEN ($when) EXECUTE FUNCTION lifecycle_failure()");
    expect(fn () => app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-next'))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($before);
})->with(['destroy', 'insert', 'revision', 'audit']);

test('postgres cross tenant target races cannot alter foreign credentials', function () {
    $foreign = tenantAiLifecycleFixture();
    $f = tenantAiLifecycleFixture();
    $foreignBefore = DB::table('tenant_ai_credentials')->where('id', $foreign['metadata']['id'])->first();
    $result = lifecyclePgRace(function ($worker) use ($f, $foreign) {
        $meta = $worker === 0 ? $f['metadata'] : $foreign['metadata'];
        app(TenantAiLifecycle::class)->revoke($f['emergency'], $meta['connection_id'], $meta['id'], 1, 1);
    });
    expect(array_column($result, 'ok'))->toBe([true, false])
        ->and(DB::table('tenant_ai_credentials')->where('id', $foreign['metadata']['id'])->first())->toEqual($foreignBefore);
});

test('postgres retained workspace deletion and pending replacement cannot orphan custody', function () {
    $f = tenantAiLifecycleFixture();
    $result = lifecyclePgRace(function ($worker) use ($f) {
        if ($worker === 0) {
            app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-next');
        } else {
            try {
                app(DeleteUserAccount::class)->execute($f['user']);
            } catch (BillingActionRefused) {
                throw new TenantAiStorageUnavailable;
            }
        }
    }, retentionDeniesBeforeLock: true);
    expect(array_column($result, 'ok'))->toBe([true, false])
        ->and(DB::table('workspaces')->where('id', $f['context']->workspaceId)->exists())->toBeTrue()
        ->and(DB::table('tenant_ai_credentials')->count())->toBe(2);
});
