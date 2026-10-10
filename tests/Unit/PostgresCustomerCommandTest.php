<?php

use App\Actions\DeleteUserAccount;
use App\AgtEnvironment;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Fiscal\IntegrationCredentials;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');
require_once __DIR__.'/../CustomerCommandFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory separate PostgreSQL command gate.');
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName())->toBe('pgsql');
    expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true]);
});

function commandWait(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Process barrier not reached.');
        }
        usleep(1000);
    }
}

/** @return list<array<string, mixed>> */
function commandProcesses(Closure $operation, int $workers = 2, bool $killed = false): array
{
    $directory = sys_get_temp_dir().'/facturac-command-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$directory/ready-$i", (string) getmypid());
                commandWait("$directory/start");
                try {
                    $value = $operation($i, $directory);
                    $result = ['ok' => true, 'value' => $value];
                } catch (Throwable $error) {
                    $result = ['ok' => false, 'exception' => $error::class,
                        'status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503];
                }
                file_put_contents("$directory/result-$i", json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork test worker.');
            }
            $pids[] = $pid;
        }
        foreach (array_keys($pids) as $i) {
            commandWait("$directory/ready-$i");
        }
        file_put_contents("$directory/start", 'go');
        $deadline = microtime(true) + 25;
        foreach ($pids as $i => $pid) {
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Worker did not finish within bound.');
                }
                usleep(1000);
            }
            if ($killed && $i === 0) {
                expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
            } else {
                expect(pcntl_wexitstatus($status))->toBe(0);
            }
        }

        return array_map(fn (int $i): array => file_exists("$directory/result-$i") ? json_decode(file_get_contents("$directory/result-$i"), true, flags: JSON_THROW_ON_ERROR) : ['killed' => true], array_keys($pids));
    } finally {
        foreach ($pids as $pid) {
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

function commandHttp(array $f, string $key, string $name = 'Padaria'): array
{
    $request = Request::create($f['url'], 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret'], 'HTTP_IDEMPOTENCY_KEY' => $key,
        'CONTENT_TYPE' => 'application/json'], json_encode(['name' => $name, 'tax_identification_number' => '5401234567'], JSON_THROW_ON_ERROR));
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
}

test('postgres two and many identical HTTP commands converge on exactly one durable effect', function (int $workers) {
    $f = commandFixture();
    $replacement = app(IntegrationCredentials::class)->rotate($f['management'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId, ['customers:create']);
    $other = [...$f, 'secret' => $replacement->revealOnce()];
    $results = commandProcesses(fn (int $i) => commandHttp($i % 2 === 0 ? $f : $other, 'simultaneous'), $workers);
    $success = [];
    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue()->and($result['value']['status'])->toBeIn([201, 409]);
        if ($result['value']['status'] === 201) {
            $success[] = $result['value']['body'];
        }
    }
    expect($success)->not->toBeEmpty()->and(count(array_unique($success)))->toBe(1)
        ->and(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1);
})->with([2, 10]);

test('postgres concurrent different payloads never execute the losing command', function () {
    $f = commandFixture();
    $results = commandProcesses(fn (int $i) => commandHttp($f, 'different', 'Name'.$i));
    $statuses = array_column(array_column($results, 'value'), 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 409])->and(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1);
});

test('postgres completed response loss is recovered by durable replay', function () {
    $f = commandFixture();
    $results = commandProcesses(function (int $i, string $directory) use ($f): array {
        if ($i === 0) {
            $response = commandHttp($f, 'lost');
            file_put_contents("$directory/committed", 'yes');

            return ['discarded' => true, 'status' => $response['status']];
        }
        commandWait("$directory/committed");

        return commandHttp($f, 'lost');
    });
    expect($results[0]['value']['status'])->toBe(201)->and($results[1]['value']['status'])->toBe(201)
        ->and(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and(Activity::where('event', 'external.command.replayed')->count())->toBe(1);
    expect($results[1]['value']['body'])->toBe(DB::table('external_command_operations')->value('response_body'));
});

test('postgres review worker death after real commit before response release replays exactly once', function () {
    $f = commandFixture();
    $results = commandProcesses(function (int $i, string $directory) use ($f): array {
        if ($i === 0) {
            Customer::created(function () use ($directory): void {
                DB::afterCommit(function () use ($directory): void {
                    expect(DB::connection()->transactionLevel())->toBe(0);
                    file_put_contents("$directory/committed", (string) getmypid());
                    commandWait("$directory/never-release-response");
                });
            });

            return commandHttp($f, 'killed-after-commit');
        }
        commandWait("$directory/committed");
        $body = DB::table('external_command_operations')->sole()->response_body;
        expect(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1);
        posix_kill((int) file_get_contents("$directory/committed"), SIGKILL);
        $response = commandHttp($f, 'killed-after-commit');
        expect($response['body'])->toBe($body);

        return $response;
    }, killed: true);
    expect($results[0]['killed'])->toBeTrue()->and($results[1]['ok'])->toBeTrue()
        ->and($results[1]['value']['status'])->toBe(201)
        ->and(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and(Activity::where('event', 'external.command.replayed')->count())->toBe(1);
});

test('postgres review withdrawal after commit before response denies subsequent replay without undoing success', function (string $kind) {
    $f = commandFixture();
    $results = commandProcesses(function (int $i, string $directory) use ($f, $kind): array {
        if ($i === 0) {
            Customer::created(function () use ($directory): void {
                DB::afterCommit(function () use ($directory): void {
                    expect(DB::connection()->transactionLevel())->toBe(0);
                    file_put_contents("$directory/committed", 'yes');
                    commandWait("$directory/withdrawn");
                });
            });

            return commandHttp($f, 'post-commit-revoke');
        }
        commandWait("$directory/committed");
        $before = (array) DB::table('external_command_operations')->sole();
        commandWithdraw($f, $kind);
        $response = commandHttp($f, 'post-commit-revoke');
        expect((array) DB::table('external_command_operations')->sole())->toBe($before);
        file_put_contents("$directory/withdrawn", 'yes');

        return $response;
    });
    expect($results[0]['ok'])->toBeTrue()->and($results[1]['ok'])->toBeTrue()
        ->and($results[0]['value']['status'])->toBe(201)->and($results[1]['value']['status'])->toBe(401)
        ->and(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and(Activity::where('event', 'external.command.replayed')->count())->toBe(0)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
})->with(['credential', 'parent']);

test('postgres killed uncommitted worker leaves no customer and retry can claim', function () {
    $f = commandFixture();
    $results = commandProcesses(function (int $i, string $directory) use ($f): array {
        if ($i === 0) {
            Activity::creating(function (Activity $activity) use ($directory): void {
                if ($activity->event === 'customer.created') {
                    file_put_contents("$directory/uncommitted", (string) getmypid());
                    commandWait("$directory/never");
                }
            });

            return commandHttp($f, 'killed');
        }
        commandWait("$directory/uncommitted");
        posix_kill((int) file_get_contents("$directory/uncommitted"), SIGKILL);

        return commandHttp($f, 'killed');
    }, killed: true);
    expect($results[0]['killed'])->toBeTrue()->and($results[1]['value']['status'])->toBe(201)
        ->and(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
});

test('postgres cannot commit an unfinished command reservation', function () {
    $f = commandFixture();
    DB::beginTransaction();
    DB::table('external_command_operations')->insert(commandReservation($f));
    try {
        DB::commit();
        $this->fail('Unfinished command committed');
    } catch (PDOException $exception) {
        expect($exception->errorInfo[0])->toBe('P0001')->and($exception->getMessage())->toContain('Unfinished command');
    }
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    expect(DB::table('external_command_operations')->count())->toBe(0);
});

function commandReservation(array $f): array
{
    return ['operation_id' => (string) Str::uuid(), 'integration_id' => $f['context']->integrationId,
        'origin_credential_id' => $f['context']->credentialId, 'workspace_id' => $f['context']->workspaceId, 'legal_entity_id' => $f['context']->entityId,
        'environment' => 'production', 'command' => 'customers.create', 'capability_version' => 1, 'canonicalizer_version' => 'command-json-v1',
        'key_hash' => str_repeat('a', 64), 'fingerprint_hash' => str_repeat('b', 64), 'origin_sponsor_user_id' => $f['user']->id,
        'origin_sponsor_attribution_id' => $f['user']->attribution_id, 'state' => 'executing', 'created_at' => now()];
}

function commandObserveWait(): void
{
    $deadline = microtime(true) + 10;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $row = DB::selectOne('SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE wait_event_type=\'Lock\' AND pg_backend_pid()=ANY(pg_blocking_pids(pid))) AS waiting');
        if ($row->waiting) {
            return;
        }
        usleep(1000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('No independently blocked writer observed.');
}

function commandWithdraw(array $f, string $kind): void
{
    match ($kind) {
        'credential' => app(IntegrationCredentials::class)->revoke($f['management'], $f['issued']->integrationPublicId, 1, credentialPublicId: $f['issued']->credentialPublicId),
        'parent' => app(IntegrationCredentials::class)->revoke($f['management'], $f['issued']->integrationPublicId, 1),
        'scope' => app(IntegrationCredentials::class)->reduceGrant($f['management'], $f['issued']->integrationPublicId, 1),
        'role' => WorkspaceMembership::findOrFail($f['context']->membershipId)->update(['role' => WorkspaceRole::Viewer]),
        'membership' => WorkspaceMembership::findOrFail($f['context']->membershipId)->update(['is_active' => false]),
        'mfa' => User::findOrFail($f['user']->id)->forceFill(['two_factor_confirmed_at' => null])->save(),
        'deletion' => app(DeleteUserAccount::class)->execute(User::findOrFail($f['user']->id)),
        'email' => User::findOrFail($f['user']->id)->forceFill(['email_verified_at' => null])->save(),
    };
}

test('postgres withdrawal linearizes against first execution and completed replay', function (string $kind, bool $replay, bool $commandFirst) {
    $f = commandFixture();
    if ($kind === 'deletion') {
        $owner = User::factory()->create();
        WorkspaceMembership::factory()->create(['workspace_id' => $f['context']->workspaceId, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
    }
    if ($replay) {
        expect(commandHttp($f, 'withdraw-race')['status'])->toBe(201);
    }
    $results = commandProcesses(function (int $i, string $directory) use ($f, $kind, $commandFirst): array {
        if ($commandFirst) {
            if ($i === 0) {
                Activity::creating(function (Activity $activity) use ($directory): void {
                    if ($activity->event === 'external.command.authorized') {
                        file_put_contents("$directory/locked", 'yes');
                        commandObserveWait();
                    }
                });

                return commandHttp($f, 'withdraw-race');
            }
            commandWait("$directory/locked");
            commandWithdraw($f, $kind);

            return ['withdrawn' => true];
        }
        if ($i === 0) {
            DB::beginTransaction();
            commandWithdraw($f, $kind);
            file_put_contents("$directory/locked", 'yes');
            commandObserveWait();
            DB::commit();

            return ['withdrawn' => true];
        }
        commandWait("$directory/locked");

        return commandHttp($f, 'withdraw-race');
    });
    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue();
    }
    $status = $results[$commandFirst ? 0 : 1]['value']['status'];
    expect($status)->toBe($commandFirst ? 201 : (in_array($kind, ['credential', 'parent'], true) ? 401 : 403));
    expect(Customer::count())->toBe(($replay || $commandFirst) ? 1 : 0)
        ->and(DB::table('external_command_operations')->count())->toBe(($replay || $commandFirst) ? 1 : 0)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(($replay || $commandFirst) ? 1 : 0);
    expect(commandHttp($f, 'withdraw-race')['status'])->toBe(in_array($kind, ['credential', 'parent'], true) ? 401 : 403);
})->with(['credential', 'parent', 'scope', 'role', 'membership', 'mfa', 'email', 'deletion'])->with([false, true])->with([false, true]);

test('postgres integration contention returns a bounded retry without reserving another command', function () {
    $f = commandFixture();
    $results = commandProcesses(function (int $i, string $directory) use ($f): array {
        if ($i === 0) {
            DB::beginTransaction();
            DB::table('integrations')->where('id', $f['context']->integrationId)->lockForUpdate()->first();
            file_put_contents("$directory/held", 'yes');
            commandWait("$directory/finished");
            DB::rollBack();

            return ['holder' => true];
        }
        commandWait("$directory/held");
        $started = microtime(true);
        $result = commandHttp($f, 'busy');
        file_put_contents("$directory/finished", 'yes');

        return [...$result, 'elapsed' => microtime(true) - $started];
    });
    expect($results[1]['value']['status'])->toBe(409)
        ->and(json_decode($results[1]['value']['body'], true)['error']['code'])->toBe('COMMAND_IN_PROGRESS')
        ->and($results[1]['value']['elapsed'])->toBeGreaterThan(0.8)->toBeLessThan(3.0)
        ->and(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('postgres domain duplicate race across integrations is separate from key replay', function () {
    $f = commandFixture();
    $issued = app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Production, 'Second', ['customers:create']);
    $other = [...$f, 'secret' => $issued->revealOnce()];
    $results = commandProcesses(fn (int $i) => commandHttp($i === 0 ? $f : $other, 'same-key'));
    $statuses = array_column(array_column($results, 'value'), 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 409])->and(Customer::count())->toBe(1)
        ->and(DB::table('external_command_operations')->count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1);
    $failed = collect($results)->first(fn ($r) => $r['value']['status'] === 409);
    expect(json_decode($failed['value']['body'], true)['error']['code'])->toBe('CUSTOMER_CONFLICT');
});

test('postgres raw changes cannot mutate completed evidence or its context', function (string $column, mixed $value) {
    $f = commandFixture();
    expect(commandHttp($f, 'immutable')['status'])->toBe(201);
    expect(fn () => DB::table('external_command_operations')->update([$column => $value]))->toThrow(QueryException::class);
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->value('state'))->toBe('succeeded');
})->with([
    ['response_body', '{}'], ['key_hash', str_repeat('c', 64)], ['fingerprint_hash', str_repeat('c', 64)],
    ['workspace_id', 999999], ['legal_entity_id', 999999], ['environment', 'homologation'],
    ['canonicalizer_version', 'other'], ['state', 'executing'], ['origin_sponsor_attribution_id', 'b3efdd1a-3287-4b93-bc09-f862611f70d0'],
    ['origin_sponsor_user_id', null],
]);

test('postgres credential expiry is rechecked after a lock wait using the database clock', function (bool $replay) {
    $f = commandFixture();
    if ($replay) {
        expect(commandHttp($f, 'expiry-wait')['status'])->toBe(201);
    }
    $results = commandProcesses(function (int $i, string $directory) use ($f): array {
        if ($i === 0) {
            DB::table('integration_credentials')->where('id', $f['context']->credentialId)->update(['expires_at' => DB::raw("clock_timestamp()+interval '500 milliseconds'")]);
            DB::beginTransaction();
            DB::table('integrations')->where('id', $f['context']->integrationId)->lockForUpdate()->first();
            file_put_contents("$directory/held", 'yes');
            commandObserveWait();
            usleep(650000);
            DB::commit();

            return ['released' => true];
        }
        commandWait("$directory/held");

        return commandHttp($f, 'expiry-wait');
    });
    expect($results[1]['value']['status'])->toBe(401)->and(Customer::count())->toBe($replay ? 1 : 0)
        ->and(DB::table('external_command_operations')->count())->toBe($replay ? 1 : 0);
})->with([false, true]);

test('postgres injected failures at every persistence stage roll back all durable effects and retry safely', function (string $stage) {
    $f = commandFixture();
    $table = match ($stage) {
        'customer' => 'customers', 'audit' => 'activity_log', 'ledger' => 'external_command_operations', 'counter' => 'external_command_capacity', 'precommit' => 'integration_credentials'
    };
    $event = in_array($stage, ['ledger', 'counter', 'precommit'], true) ? 'UPDATE' : 'INSERT';
    $condition = match ($stage) {
        'audit' => "NEW.event='customer.created'", 'ledger' => "NEW.state='succeeded'", 'precommit' => 'NEW.last_used_at IS NOT NULL', default => 'TRUE'
    };
    DB::statement("CREATE FUNCTION phase5a_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Injected test fault'; END IF; RETURN NEW; END; \$\$");
    DB::statement("CREATE TRIGGER phase5a_fault AFTER $event ON $table FOR EACH ROW EXECUTE FUNCTION phase5a_fault()");
    try {
        expect(commandHttp($f, 'rollback')['status'])->toBe(503)->and(Customer::count())->toBe(0)
            ->and(DB::table('external_command_operations')->count())->toBe(0)->and(DB::table('external_command_capacity')->count())->toBe(0)
            ->and(Activity::where('event', 'customer.created')->count())->toBe(0)->and(Activity::where('event', 'external.command.authorized')->count())->toBe(0);
    } finally {
        DB::statement("DROP TRIGGER phase5a_fault ON $table");
        DB::statement('DROP FUNCTION phase5a_fault()');
    }
    expect(commandHttp($f, 'rollback')['status'])->toBe(201)->and(Customer::count())->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1)->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
})->with(['customer', 'audit', 'ledger', 'counter', 'precommit']);

test('postgres statement and transaction timeouts fail closed and a fresh connection safely retries', function (string $kind) {
    $f = commandFixture();
    if ($kind === 'statement') {
        DB::statement('CREATE FUNCTION phase5a_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(2.5); RETURN NEW; END; $$');
        DB::statement('CREATE TRIGGER phase5a_delay BEFORE INSERT ON customers FOR EACH ROW EXECUTE FUNCTION phase5a_delay()');
    } else {
        Activity::creating(function (Activity $a): void {
            if ($a->event === 'customer.created') {
                usleep(5_300_000);
            }
        });
    }
    $started = microtime(true);
    expect(commandHttp($f, 'timeout')['status'])->toBe(503)->and(microtime(true) - $started)->toBeLessThan(8.0);
    DB::purge();
    if ($kind === 'statement') {
        DB::statement('DROP TRIGGER phase5a_delay ON customers');
        DB::statement('DROP FUNCTION phase5a_delay()');
    } else {
        Activity::flushEventListeners();
        Activity::clearBootedModels();
    }
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
    expect(commandHttp($f, 'timeout')['status'])->toBe(201)->and(Customer::count())->toBe(1);
    expect(DB::selectOne('SHOW statement_timeout')->statement_timeout)->toBe('0')
        ->and(DB::selectOne('SHOW lock_timeout')->lock_timeout)->toBe('0')
        ->and(DB::selectOne('SHOW transaction_timeout')->transaction_timeout)->toBe('0');
})->with(['statement', 'transaction']);

test('postgres native minute command quota is exact across independent rotated callers', function () {
    $f = commandFixture();
    $replacement = app(IntegrationCredentials::class)->rotate($f['management'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId, ['customers:create']);
    $second = [...$f, 'secret' => $replacement->revealOnce()];
    while (time() % 60 > 40) {
        usleep(100000);
    }
    $results = commandProcesses(fn (int $i) => commandHttp($i % 2 === 0 ? $f : $second, 'quota'), 12);
    $statuses = array_column(array_column($results, 'value'), 'status');
    expect(count(array_filter($statuses, fn ($s) => $s === 429)))->toBe(2)
        ->and(count(array_filter($statuses, fn ($s) => in_array($s, [201, 409], true))))->toBe(10)
        ->and(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1);
});

function seedCommandSuccesses(array $f, int $count): void
{
    for ($start = 1; $start <= $count; $start += 1000) {
        $end = min($start + 999, $count);
        DB::transaction(function () use ($f, $start, $end): void {
            DB::insert("INSERT INTO external_command_operations (operation_id,integration_id,origin_credential_id,workspace_id,legal_entity_id,environment,command,capability_version,canonicalizer_version,key_hash,fingerprint_hash,origin_sponsor_user_id,origin_sponsor_attribution_id,state,created_at) SELECT gen_random_uuid(),?,?,?,?, 'production','customers.create',1,'command-json-v1',encode(sha256(convert_to('seed-'||g::text,'UTF8')),'hex'),repeat('b',64),?,?,'executing',clock_timestamp() FROM generate_series(?::integer,?::integer) g", [$f['context']->integrationId, $f['context']->credentialId, $f['context']->workspaceId, $f['context']->entityId, $f['user']->id, $f['user']->attribution_id, $start, $end]);
            DB::update("UPDATE external_command_operations SET state='succeeded',result_public_id='01arz3ndektsv4rrffq69g5fav',http_status=201,response_body=jsonb_build_object('data',jsonb_build_object('public_id','01arz3ndektsv4rrffq69g5fav'),'meta',jsonb_build_object('operation_id',operation_id::text))::text,completed_at=clock_timestamp() WHERE integration_id=? AND state='executing'", [$f['context']->integrationId]);
        });
    }
}

test('postgres guarded retained capacity reaches its bound without blocking authorized replay and lookups remain indexed', function () {
    $f = commandFixture();
    expect(commandHttp($f, 'retained')['status'])->toBe(201);
    seedCommandSuccesses($f, 99998);
    expect((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(99999);
    $input = CustomerCreateInput::external('{"name":"Final","tax_identification_number":"5401234568"}');
    app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'last');
    expect((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(100000);
    expect(commandHttp($f, 'over')['status'])->toBe(503)->and(Customer::count())->toBe(2)
        ->and(DB::table('external_command_operations')->count())->toBe(100000);
    expect(commandHttp($f, 'retained')['status'])->toBe(201)->and(Customer::count())->toBe(2);
    expect(fn () => DB::table('external_command_capacity')->increment('completed_count'))->toThrow(QueryException::class);
    expect(fn () => DB::table('external_command_capacity')->delete())->toThrow(QueryException::class);
    DB::statement('ANALYZE external_command_operations');
    $plan = json_decode(DB::selectOne("EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT * FROM external_command_operations WHERE integration_id=? AND workspace_id=? AND legal_entity_id=? AND environment='production' AND command='customers.create' AND capability_version=1 AND key_hash=?", [$f['context']->integrationId, $f['context']->workspaceId, $f['context']->entityId, hash('sha256', 'retained')])->{'QUERY PLAN'}, true);
    expect($plan[0]['Plan']['Node Type'])->toBe('Index Scan')->and($plan[0]['Plan']['Actual Rows'])->toEqual(1)
        ->and($plan[0]['Execution Time'])->toBeLessThan(100);
    $nif = json_decode(DB::selectOne('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT id FROM customers WHERE legal_entity_id=? AND tax_identification_number=?', [$f['context']->entityId, '5401234567'])->{'QUERY PLAN'}, true);
    file_put_contents('/private/tmp/phase5a-postgres-plans.json', json_encode(['ledger_rows' => 100000, 'ledger' => $plan, 'nif' => $nif], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('postgres default price-list deletion linearizes safely in both orders against customer creation', function (bool $commandFirst) {
    $f = commandFixture();
    $priceList = PriceList::factory()->create(['workspace_id' => $f['context']->workspaceId, 'legal_entity_id' => $f['entity']->id, 'is_default' => true]);
    $results = commandProcesses(function (int $i, string $directory) use ($f, $priceList, $commandFirst): array {
        if ($commandFirst) {
            if ($i === 0) {
                Customer::creating(function (Customer $customer) use ($directory, $priceList): void {
                    expect($customer->price_list_id)->toBe($priceList->id);
                    file_put_contents("$directory/locked", 'yes');
                    commandObserveWait();
                });

                return commandHttp($f, 'default-delete');
            }
            commandWait("$directory/locked");
            PriceList::findOrFail($priceList->id)->delete();

            return ['deleted' => true];
        }
        if ($i === 0) {
            DB::beginTransaction();
            PriceList::findOrFail($priceList->id)->delete();
            file_put_contents("$directory/locked", 'yes');
            commandObserveWait();
            DB::commit();

            return ['deleted' => true];
        }
        commandWait("$directory/locked");

        return commandHttp($f, 'default-delete');
    });
    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue();
    }
    expect($results[$commandFirst ? 0 : 1]['value']['status'])->toBe(201)
        ->and(Customer::query()->sole()->price_list_id)->toBeNull()
        ->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
})->with([false, true]);
