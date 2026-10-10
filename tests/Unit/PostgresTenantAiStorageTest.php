<?php

use App\Actions\DeleteUserAccount;
use App\Fiscal\TenantAiCatalogue;
use App\Fiscal\TenantAiStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');
require_once __DIR__.'/../TenantAiFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('PostgreSQL storage gate required.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    tenantAiRoot();
    $directory = storage_path('framework/testing');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $this->keyPath = $directory.'/tai-pg-'.Str::uuid();
    file_put_contents($this->keyPath, random_bytes(32));
    chmod($this->keyPath, 0600);
    config(['tenant_ai.kek_root' => realpath($directory), 'tenant_ai.kek_version' => 'test-v1', 'tenant_ai.kek_files' => ['test-v1' => $this->keyPath]]);
    $this->secret = 'synthetic-'.bin2hex(random_bytes(24));
});
afterEach(function () {
    if (isset($this->keyPath)) {
        @unlink($this->keyPath);
    }
});

test('postgres direct SQL ownership identity default and deletion constraints hold', function () {
    $f = tenantAiFixture();
    $meta = app(TenantAiStorage::class)->configure($f['context'], $this->secret);
    $other = tenantAiFixture();
    $violations = [
        fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['workspace_id' => $other['context']->workspaceId]),
        fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['secret_ciphertext' => 'changed']),
        fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['state' => 'active', 'verification_state' => 'verified']),
        fn () => DB::table('tenant_ai_settings')->where('workspace_id', $f['context']->workspaceId)->update(['customer_search_enabled' => null, 'revision' => 2]),
        fn () => DB::table('tenant_ai_settings')->where('workspace_id', $f['context']->workspaceId)->update(['customer_search_enabled' => true]),
        fn () => DB::table('workspaces')->where('id', $f['context']->workspaceId)->delete(),
        fn () => DB::table('tenant_ai_settings')->where('workspace_id', $f['context']->workspaceId)->update(['mode' => 'customer_managed', 'profile_id' => TenantAiCatalogue::ID, 'connection_id' => $meta['connection_id'], 'revision' => 2]),
    ];
    foreach ($violations as $violation) {
        expect(fn () => DB::transaction($violation))->toThrow(PDOException::class);
    }
    expect(DB::table('tenant_ai_credentials')->count())->toBe(1);
});

test('postgres creator relationship may disappear while A1 evidence remains immutable', function () {
    $f = tenantAiFixture();
    $meta = app(TenantAiStorage::class)->configure($f['context'], $this->secret);
    $snapshot = $f['user']->attribution_id;
    DB::table('users')->where('id', $f['user']->id)->delete();
    foreach (['tenant_ai_settings', 'tenant_ai_connections', 'tenant_ai_credentials'] as $table) {
        $row = DB::table($table)->first();
        expect($row->creator_user_id)->toBeNull()->and($row->creator_attribution_id)->toBe($snapshot);
    }
});

test('postgres populated custody rollback refuses and empty migration roundtrip succeeds', function () {
    $schema = require database_path('migrations/2026_10_09_010739_create_tenant_ai_storage_tables.php');
    $seed = require database_path('migrations/2026_10_09_011029_seed_tenant_ai_storage_catalogue.php');
    $successors = array_map(fn ($path) => require $path, glob(database_path('migrations/*_ai_verification_*.php')));
    foreach (array_reverse($successors) as $migration) {
        $migration->down();
    }
    $seed->down();
    $schema->down();
    $schema->up();
    $seed->up();
    foreach ($successors as $migration) {
        $migration->up();
    }
    expect(DB::table('tenant_ai_settings')->count())->toBe(0);
    $f = tenantAiFixture();
    app(TenantAiStorage::class)->configure($f['context'], $this->secret);
    expect(fn () => $schema->down())->toThrow(RuntimeException::class);
});

/** @return list<array<string,mixed>> */
function tenantAiRace(Closure $operation): array
{
    $directory = sys_get_temp_dir().'/tai-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $children = [];
    $wait = static function (string $file): void {
        $until = microtime(true) + 15;
        while (! file_exists($file)) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Barrier expired');
            }usleep(1000);
        }
    };
    try {
        for ($n = 0; $n < 2; $n++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$directory/ready-$n", 'ready');
                $wait("$directory/start");
                try {
                    $operation($n);
                    $ok = true;
                } catch (Throwable) {
                    $ok = false;
                }file_put_contents("$directory/result-$n", json_encode(['ok' => $ok]));
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Fork failed');
            }$children[] = $pid;
        }
        for ($n = 0; $n < 2; $n++) {
            $wait("$directory/ready-$n");
        }file_put_contents("$directory/start", 'go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(fn ($n) => json_decode(file_get_contents("$directory/result-$n"), true), [0, 1]);
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }foreach (glob("$directory/*") as $p) {
            unlink($p);
        }rmdir($directory);
        DB::purge();
    }
}

test('postgres concurrent candidates preserve one pending version with atomic audit', function () {
    $f = tenantAiFixture();
    $meta = app(TenantAiStorage::class)->configure($f['context'], $this->secret);
    $connection = (array) DB::table('tenant_ai_connections')->where('id', $meta['connection_id'])->first();
    $connection['id'] = (string) Str::uuid();
    DB::table('tenant_ai_connections')->insert($connection);
    $result = tenantAiRace(fn () => app(TenantAiStorage::class)->configure($f['context'], $this->secret, $connection['id']));
    expect(array_column($result, 'ok'))->toContain(true, false);
    expect(DB::table('tenant_ai_credentials')->where('connection_id', $connection['id'])->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_configured')->count())->toBe(2);
});

test('postgres deletion racing custody never leaves orphan secret or partial deletion', function () {
    $f = tenantAiFixture();
    $workspaceId = $f['context']->workspaceId;
    tenantAiRace(function ($worker) use ($f) {
        if ($worker === 0) {
            app(TenantAiStorage::class)->configure($f['context'], $this->secret);
        } else {
            app(DeleteUserAccount::class)->execute($f['user']);
        }
    });
    $exists = DB::table('workspaces')->where('id', $workspaceId)->exists();
    $count = DB::table('tenant_ai_credentials')->where('workspace_id', $workspaceId)->count();
    expect($exists ? $count === 1 : $count === 0)->toBeTrue();
});

test('postgres composite tenant FK and pending uniqueness reject independent raw inserts', function () {
    $a = tenantAiFixture();
    $meta = app(TenantAiStorage::class)->configure($a['context'], $this->secret);
    $row = (array) DB::table('tenant_ai_credentials')->where('id', $meta['id'])->first();
    $row['id'] = (string) Str::uuid();
    $row['version_number'] = 2;
    try {
        DB::table('tenant_ai_credentials')->insert($row);
        $this->fail('Expected unique rejection');
    } catch (QueryException $e) {
        expect($e->errorInfo[0])->toBe('23505');
    }
    $connection = (array) DB::table('tenant_ai_connections')->first();
    $connection['id'] = (string) Str::uuid();
    DB::table('tenant_ai_connections')->insert($connection);
    $b = tenantAiFixture();
    $row['connection_id'] = $connection['id'];
    $row['workspace_id'] = $b['context']->workspaceId;
    try {
        DB::table('tenant_ai_credentials')->insert($row);
        $this->fail('Expected ownership FK rejection');
    } catch (QueryException $e) {
        expect($e->errorInfo[0])->toBe('P0001');
    }
    expect(DB::table('tenant_ai_credentials')->count())->toBe(1);
});
