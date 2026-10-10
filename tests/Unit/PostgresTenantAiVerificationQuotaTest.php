<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiContext;
use App\Fiscal\TenantAiVerificationAdmission;
use App\Fiscal\TenantAiVerificationQuota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql', 'verification-quota');
require_once __DIR__.'/../TenantAiVerificationQuotaFixtures.php';

beforeEach(function () {
    if (getenv('VERIFICATION_QUOTA_PG_GATE') !== '1') {
        $this->markTestSkipped('Dedicated PostgreSQL verification quota gate required.');
    }
    expect(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('facturac_test_verification_cr1_runtime');
});

function quotaContext(User $user, array $fixture): TenantAiContext
{
    config(['tenant_ai.deployment_id' => $fixture['row']['deployment_id']]);
    auth('web')->login($user);
    $session = new Store('quota-test', new ArraySessionHandler(120));
    $session->start();
    $session->put((string) config('work_session.started_at_key'), time());
    $session->put('auth.password_confirmed_at', time());
    $request = Request::create('/test-only', 'POST');
    $request->setLaravelSession($session);
    $request->headers->set('X-CSRF-TOKEN', $session->token());
    $request->setUserResolver(fn () => $user);

    return TenantAiContext::resolve($request, $fixture['row']['workspace_public_id']);
}

/** @param array<string, mixed> $fixture */
function quotaPersist(array $fixture, string $time): void
{
    $row = $fixture['row'];
    $row['admitted_at'] = $time;
    DB::table('assistant_provider_attempts')->insert($row);
    foreach ($fixture['allocations'] as $allocation) {
        $allocation['created_at'] = $time;
        DB::table('assistant_provider_allocations')->insert($allocation);
    }
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
}

test('CR1 requires its primary outermost lock and retains committed cross root admissions', function () {
    $user = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $fixtures = [];
    foreach (range(1, 7) as $index) {
        $fixtures[] = DB::transaction(fn () => verificationQuotaFixture($user));
    }
    $quota = new TenantAiVerificationQuota;
    foreach (range(0, 4) as $index) {
        $context = quotaContext($user, $fixtures[$index]);
        expect(fn () => $quota->acquire($context, hrtime(true) / 1e9 + 10))->toThrow(TenantAiStorageUnavailable::class);
        DB::transaction(function () use ($quota, $context, $fixtures, $index) {
            expect(fn () => $quota->assertAvailable($context))->toThrow(TenantAiStorageUnavailable::class);
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            $instant = $quota->assertAvailable($context);
            quotaPersist($fixtures[$index], $instant->format('Y-m-d H:i:s.uP'));
        });
    }
    expect(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $user->attribution_id)->count())->toBe(5);
    $context = quotaContext($user, $fixtures[5]);
    expect(fn () => DB::transaction(function () use ($quota, $context) {
        $quota->acquire($context, hrtime(true) / 1e9 + 10);
        $quota->assertAvailable($context);
    }))->toThrow(TenantAiStorageUnavailable::class);
    expect(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $user->attribution_id)->count())->toBe(5);
});

test('CR1 serializes the fifth admission across processes without a prewait snapshot', function (bool $sharedRoot, bool $crossProvider) {
    $user = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $fixtures = [];
    foreach (range(0, 5) as $index) {
        $fixtures[] = DB::transaction(fn () => verificationQuotaFixture($user, $sharedRoot && $index > 0 ? $fixtures[0]['budgets'] : null, true, $crossProvider && $index === 5 ? 'openai' : 'anthropic'));
    }
    foreach (range(0, 3) as $index) {
        $context = quotaContext($user, $fixtures[$index]);
        DB::transaction(function () use ($context, $fixtures, $index) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixtures[$index], $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    }
    $results = quotaRace(function (int $worker) use ($user, $fixtures) {
        $fixture = $fixtures[$worker + 4];
        $context = quotaContext($user, $fixture);
        DB::transaction(function () use ($context, $fixture) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixture, $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    });
    expect(array_column($results, 'result'))->toBe(['committed', 'quota_refused'])
        ->and(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $user->attribution_id)->count())->toBe(5);
})->with(['same root' => [true, false], 'different roots tenants and credentials' => [false, false], 'different providers inert metadata only' => [false, true]]);

test('CR1 rollback releases authority and consumes no admission', function () {
    $user = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $fixtures = [DB::transaction(fn () => verificationQuotaFixture($user)), DB::transaction(fn () => verificationQuotaFixture($user))];
    $results = quotaRace(function (int $worker) use ($user, $fixtures) {
        $context = quotaContext($user, $fixtures[$worker]);
        DB::transaction(function () use ($context, $fixtures, $worker) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixtures[$worker], $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
            if ($worker === 0) {
                throw new LogicException('Forced rollback');
            }
        });
    });
    expect(array_column($results, 'result'))->toBe(['error', 'committed'])
        ->and(DB::table('assistant_provider_attempts')->where('id', $fixtures[0]['attempt'])->exists())->toBeFalse()
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $fixtures[0]['attempt'])->exists())->toBeFalse()
        ->and(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $user->attribution_id)->count())->toBe(1);
});

test('CR1 lock timeout fails closed without admission or allocations', function () {
    $user = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $fixtures = [DB::transaction(fn () => verificationQuotaFixture($user)), DB::transaction(fn () => verificationQuotaFixture($user))];
    $results = quotaRace(function (int $worker) use ($user, $fixtures) {
        $context = quotaContext($user, $fixtures[$worker]);
        DB::transaction(function () use ($context, $fixtures, $worker) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixtures[$worker], $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    }, 'timeout');
    expect($results[0]['result'])->toBe('committed')->and($results[1]['state'])->toBe('55P03')
        ->and(DB::table('assistant_provider_attempts')->where('id', $fixtures[1]['attempt'])->exists())->toBeFalse()
        ->and(DB::table('assistant_provider_allocations')->where('attempt_id', $fixtures[1]['attempt'])->exists())->toBeFalse();
});

test('ordinary different actors do not wait on each others CR1 advisory key', function () {
    $users = [User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]),
        User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()])];
    $fixtures = [DB::transaction(fn () => verificationQuotaFixture($users[0])), DB::transaction(fn () => verificationQuotaFixture($users[1]))];
    $results = quotaRace(function (int $worker) use ($users, $fixtures) {
        $context = quotaContext($users[$worker], $fixtures[$worker]);
        DB::transaction(function () use ($context, $fixtures, $worker) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixtures[$worker], $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    }, 'independent');
    expect(array_column($results, 'result'))->toBe(['committed', 'committed']);
});

test('CR1 deterministic advisory collision only serializes independent exact actor counts', function () {
    $users = [];
    foreach (['000018af-0000-4000-8000-000000000001', '000072d4-0000-4000-8000-000000000001'] as $identity) {
        Str::createUuidsUsing(fn () => Uuid::fromString($identity));
        try {
            $users[] = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
        } finally {
            Str::createUuidsNormally();
        }
    }
    expect(TenantAiVerificationQuota::key($users[0]->attribution_id))->toBe(434458243)
        ->and(TenantAiVerificationQuota::key($users[1]->attribution_id))->toBe(434458243);
    $fixtures = array_map(fn ($user) => DB::transaction(fn () => verificationQuotaFixture($user)), $users);
    $results = quotaRace(function ($worker) use ($users, $fixtures) {
        $context = quotaContext($users[$worker], $fixtures[$worker]);
        DB::transaction(function () use ($context, $fixtures, $worker) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixtures[$worker], $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    });
    expect(array_column($results, 'result'))->toBe(['committed', 'committed']);
    foreach ($users as $user) {
        expect(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $user->attribution_id)->count())->toBe(1);
    }    foreach (range(1, 4) as $index) {
        $fixture = DB::transaction(fn () => verificationQuotaFixture($users[0]));
        $context = quotaContext($users[0], $fixture);
        DB::transaction(function () use ($context, $fixture): void {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);
            quotaPersist($fixture, $quota->assertAvailable($context)->format('Y-m-d H:i:s.uP'));
        });
    }
    $context = quotaContext($users[1], $fixtures[1]);
    DB::transaction(function () use ($context): void {
        $quota = new TenantAiVerificationQuota;
        $quota->acquire($context, hrtime(true) / 1e9 + 10);
        expect($quota->assertAvailable($context))->toBeInstanceOf(CarbonImmutable::class);
    });
    expect(DB::table('assistant_provider_attempts')->where('actor_attribution_id', $users[0]->attribution_id)->count())->toBe(5);
});

test('CR1 retained cross root provider history uses bounded zero four five actor index plans', function () {
    $user = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $fixtures = [];
    foreach (range(0, 3) as $index) {
        $fixtures[] = DB::transaction(fn () => verificationQuotaFixture($user, null, true, $index % 2 === 0 ? 'anthropic' : 'openai'));
    }
    $clock = TenantAiVerificationAdmission::instant();
    $past = $clock->subSeconds(7200);
    $persist = function (array $fixture, CarbonImmutable $instant) {
        $row = $fixture['row'];
        $row['id'] = (string) Str::uuid();
        $row['interaction_id'] = (string) Str::uuid();
        $row['admitted_at'] = $row['finalized_at'] = $instant->format('Y-m-d H:i:s.uP');
        $row['day_start'] = $instant->startOfDay()->format('Y-m-d H:i:s.uP');
        $row['month_start'] = $instant->startOfMonth()->format('Y-m-d H:i:s.uP');
        $row['state'] = 'failed';
        $row['outcome'] = 'usage_unknown';
        $row['verification_outcome'] = 'local_policy_denied';
        $row['promotion_disposition'] = 'not_applicable';
        $completion = array_intersect_key($row, array_flip(['state', 'outcome', 'verification_outcome', 'promotion_disposition', 'finalized_at']));
        foreach (array_keys($completion) as $key) {
            unset($row[$key]);
        }
        $row['state'] = 'admitted';
        $row['verification_outcome'] = 'not_observed';
        DB::table('assistant_provider_attempts')->insert($row);
        foreach ($fixture['allocations'] as $allocation) {
            $window = (array) DB::table('assistant_provider_windows')->where('id', $allocation['window_id'])->firstOrFail();
            unset($window['id']);
            $monthly = str_ends_with($window['scope'], 'month');
            $start = $monthly ? $instant->startOfMonth() : $instant->startOfDay();
            $window['window_start'] = $start->format('Y-m-d H:i:s.uP');
            $window['window_end'] = ($monthly ? $start->addMonth() : $start->addDay())->format('Y-m-d H:i:s.uP');
            $identity = array_intersect_key($window, array_flip(['budget_id', 'scope', 'scope_key', 'window_start']));
            DB::table('assistant_provider_windows')->insertOrIgnore($window);
            $id = DB::table('assistant_provider_windows')->where($identity)->value('id');
            $allocation['window_id'] = $id;
            $allocation['attempt_id'] = $row['id'];
            $allocation['created_at'] = $row['admitted_at'];
            DB::table('assistant_provider_allocations')->insert($allocation);
        }
        DB::table('assistant_provider_attempts')->where('id', $row['id'])->update($completion);

        return $row['id'];
    };
    DB::transaction(function () use ($fixtures, $persist, $past) {
        foreach (range(0, 999) as $index) {
            $persist($fixtures[$index % 4], $past->subSeconds($index));
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
    $plans = [];
    $context = quotaContext($user, $fixtures[0]);
    foreach ([0, 4, 5] as $count) {
        if ($count > 0) {
            DB::transaction(function () use ($fixtures, $persist, $clock, $count) {
                foreach (range(1, $count === 4 ? 4 : 1) as $index) {
                    $persist($fixtures[$index % 4], $clock);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        }
        DB::statement('ANALYZE assistant_provider_attempts');
        $record = DB::selectOne("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM assistant_provider_attempts WHERE contract_version='gateway_v1' AND purpose='connection_probe' AND actor_attribution_id=? AND admitted_at>? ORDER BY admitted_at,id LIMIT 5", [$user->attribution_id, $clock->subSeconds(3600)->format('Y-m-d H:i:s.uP')]);
        $plan = json_decode($record->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        expect((int) $plan['Plan']['Actual Rows'])->toBe($count)->and($plan['Execution Time'])->toBeLessThan(1000);
        $walk = function (array $node) use (&$walk) {
            expect($node['Node Type'])->not->toBe('Seq Scan')->not->toBe('Sort')
                ->and($node['Temp Written Blocks'] ?? 0)->toBe(0)
                ->and($node['Actual Rows'])->toBeLessThanOrEqual(5);
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan['Plan']);
        $plans[(string) $count] = $plan;
        $operation = fn () => DB::transaction(function () use ($context) {
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context, hrtime(true) / 1e9 + 10);

            return $quota->assertAvailable($context);
        });
        if ($count === 5) {
            expect($operation)->toThrow(TenantAiStorageUnavailable::class);
        } else {
            expect($operation())->toBeInstanceOf(CarbonImmutable::class);
        }
    }
    $contextPlans = [];
    foreach ([['connection_id', $fixtures[1]['connection'], 60, 1], ['workspace_id', $fixtures[1]['row']['workspace_id'], 3600, 5]] as [$column, $value, $seconds, $limit]) {
        $record = DB::selectOne("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM assistant_provider_attempts WHERE contract_version='gateway_v1' AND purpose='connection_probe' AND $column=? AND admitted_at>? ORDER BY admitted_at,id LIMIT $limit", [$value, $clock->subSeconds($seconds)->format('Y-m-d H:i:s.uP')]);
        $plan = json_decode($record->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        $walk($plan['Plan']);
        expect((int) $plan['Plan']['Actual Rows'])->toBeGreaterThan(0)->toBeLessThanOrEqual($limit)
            ->and($plan['Execution Time'])->toBeLessThan(1000);
        $contextPlans[$column] = $plan;
    }
    $boundaryActor = User::factory()->create(['two_factor_secret' => 'inert', 'two_factor_confirmed_at' => now()]);
    $boundaryFixtures = [];
    foreach (range(0, 3) as $index) {
        $boundaryFixtures[] = DB::transaction(fn () => verificationQuotaFixture($boundaryActor));
    }
    $boundaryContext = quotaContext($boundaryActor, $boundaryFixtures[0]);
    $captured = null;
    DB::listen(function ($query) use (&$captured): void {
        if (str_starts_with($query->sql, 'select "id" from "assistant_provider_attempts"') && str_contains($query->sql, '"actor_attribution_id"')) {
            $captured = [$query->sql, $query->bindings];
        }
    });
    $q = DB::transaction(function () use ($boundaryContext) {
        $quota = new TenantAiVerificationQuota;
        $quota->acquire($boundaryContext, hrtime(true) / 1e9 + 10);

        return $quota->assertAvailable($boundaryContext);
    });
    $boundary = $q->subSeconds(3600);
    $ids = DB::transaction(function () use ($persist, $boundaryFixtures, $boundary, $q): array {
        $ids = [];
        foreach ([$boundary->subMicrosecond(), $boundary, $boundary->addMicrosecond(), $q->addHour()] as $index => $instant) {
            $ids[] = $persist($boundaryFixtures[$index], $instant);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $ids;
    });
    expect($captured)->not->toBeNull();
    $rows = DB::select($captured[0], $captured[1], false);
    expect(array_column($rows, 'id'))->toBe([$ids[2], $ids[3]]);
    file_put_contents(storage_path('framework/testing/cr1-quota-plans.json'), json_encode(['retained_structural_rows' => 1000,
        'plans' => $plans, 'context_plans' => $contextPlans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
