<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql', 'ai-verification-schema');
require_once __DIR__.'/../AiVerificationMigrationFixtures.php';

function verificationRaceWait(Closure $ready): void
{
    $deadline = microtime(true) + 15;
    while (! $ready()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Schema rehearsal barrier expired');
        }
        usleep(1000);
    }
}

test('independent schema contenders serialize under canonical locks with only one rotation committed', function () {
    if (getenv('AI_SCHEMA_RACE_PG_GATE') !== '1') {
        $this->markTestSkipped('Dedicated disposable schema race gate required.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('facturac_test_phase7b3c1_r3_races_utf8');
    DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $fixture = DB::transaction(fn () => verificationMigrationActiveFixture());
    $attempt = DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    $directory = sys_get_temp_dir().'/ai-schema-race-'.Str::uuid();
    mkdir($directory, 0700);
    $children = [];
    DB::purge();
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                $locks = [];
                try {
                    file_put_contents("$directory/pid-$worker", (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    if ($worker === 1) {
                        verificationRaceWait(fn () => file_exists("$directory/held"));
                    }
                    $candidate = DB::transaction(function () use ($fixture, $attempt, $worker, $directory, &$locks) {
                        DB::statement("SET LOCAL lock_timeout='250ms'");
                        DB::statement("SET LOCAL statement_timeout='1s'");
                        DB::table('ai_gateway_controls')->where('deployment_id', $attempt->deployment_id)->orderBy('id')->lockForUpdate()->get();
                        $locks[] = 'root';
                        if ($worker === 0) {
                            file_put_contents("$directory/held", 'held');
                            verificationRaceWait(fn () => file_exists("$directory/release"));
                        }
                        DB::table('assistant_provider_controls')->whereIn('budget_id', [$attempt->budget_id, $attempt->aggregate_budget_id, $attempt->usage_budget_id])->orderBy('budget_id')->lockForUpdate()->get();
                        $locks[] = 'budgets';
                        DB::table('tenant_ai_settings')->where('workspace_id', $attempt->workspace_id)->lockForUpdate()->first();
                        $locks[] = 'settings';
                        DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->lockForUpdate()->first();
                        $locks[] = 'connection';
                        DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->orderBy('id')->lockForUpdate()->get();
                        $locks[] = 'credentials';
                        if (DB::table('tenant_ai_credentials')->where('id', $fixture['credential'])->value('state') !== 'active') {
                            throw new RuntimeException('Stale original active candidate');
                        }
                        DB::table('assistant_provider_tenants')->where('id', $attempt->owner_approval_id)->lockForUpdate()->first();
                        DB::table('assistant_provider_acknowledgements')->where('id', $attempt->acknowledgement_id)->lockForUpdate()->first();
                        $locks[] = 'approval_ack';
                        DB::table('assistant_provider_windows')->whereIn('budget_id', [$attempt->budget_id, $attempt->aggregate_budget_id, $attempt->usage_budget_id])
                            ->orderBy('budget_id')->orderByRaw("CASE scope WHEN 'deployment_month' THEN 0 WHEN 'deployment_day' THEN 1 WHEN 'workspace_month' THEN 2 WHEN 'workspace_day' THEN 3 ELSE 4 END")
                            ->orderByRaw('scope_key COLLATE "C"')->orderBy('window_start')->lockForUpdate()->get();
                        $locks[] = 'windows';

                        return verificationMigrationRotate($fixture);
                    });
                    $result = ['ok' => true, 'candidate' => $candidate, 'locks' => $locks];
                } catch (Throwable $error) {
                    $result = ['ok' => false, 'error' => $error->getMessage(), 'locks' => $locks];
                }
                file_put_contents("$directory/result-$worker", json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Cannot fork schema rehearsal');
            }
            $children[] = $pid;
        }
        verificationRaceWait(fn () => file_exists("$directory/held") && file_exists("$directory/pid-1"));
        $waitingPid = (int) file_get_contents("$directory/pid-1");
        verificationRaceWait(fn () => (bool) DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock') AS waiting", [$waitingPid])->waiting);
        file_put_contents("$directory/release", 'go');
        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn (int $worker): array => json_decode(file_get_contents("$directory/result-$worker"), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        expect($results[0]['ok'])->toBeTrue()->and($results[1]['ok'])->toBeFalse()
            ->and($results[1]['error'])->toBe('Stale original active candidate')
            ->and($results[0]['locks'])->toBe(['root', 'budgets', 'settings', 'connection', 'credentials', 'approval_ack', 'windows'])
            ->and($results[1]['locks'])->toBe(['root', 'budgets', 'settings', 'connection', 'credentials'])
            ->and(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(1)
            ->and(DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->value('active_generation'))->toBe(2);
    } finally {
        foreach ($children as $child) {
            if (pcntl_waitpid($child, $status, WNOHANG) === 0) {
                posix_kill($child, SIGKILL);
                pcntl_waitpid($child, $status);
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::purge();
    }
});
