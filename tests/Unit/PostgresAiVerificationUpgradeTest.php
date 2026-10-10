<?php

use App\Fiscal\TenantAiVerificationSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql', 'ai-verification-schema');
require_once __DIR__.'/../TenantAiLifecycleFixtures.php';

beforeEach(function () {
    if (getenv('AI_UPGRADE_PG_GATE') !== '1') {
        $this->markTestSkipped('Dedicated disposable PostgreSQL upgrade gate required.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('facturac_test_phase7b3c1_r3_upgrade_utf8');
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
    DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
    $historical = array_values(array_filter(glob(database_path('migrations/*.php')),
        fn (string $path): bool => ! str_contains(basename($path), '_ai_verification_')));
    $this->artisan('migrate:fresh', ['--force' => true, '--path' => $historical, '--realpath' => true])->assertExitCode(0);
    tenantAiLifecycleSetup($this);
});

afterEach(function () {
    if (getenv('AI_UPGRADE_PG_GATE') === '1') {
        tenantAiLifecycleCleanup($this);
    }
});

test('populated historical custody boundary upgrades without changing secrets identities consent or authority', function () {
    tenantAiLifecycleFixture();
    $before = tenantAiLifecycleSnapshot();
    $credentials = DB::table('tenant_ai_credentials')->get();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    foreach ($before as $table => $json) {
        $columns = array_keys(json_decode($json, true)[0] ?? []);
        if ($columns !== []) {
            $after = DB::table($table)->select($columns)->orderBy($table === 'tenant_ai_settings' ? 'workspace_id' : 'id')->get()->toJson();
            expect(hash('sha256', $after))->toBe(hash('sha256', $json));
        }
    }
    expect(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(0)
        ->and(DB::table('tenant_ai_credentials')->where('verification_state', 'verified')->count())->toBe(0)
        ->and(DB::table('assistant_provider_allocations')->count())->toBe(0);
    foreach ($credentials as $credential) {
        expect(DB::table('tenant_ai_credentials')->find($credential->id)->secret_ciphertext)->toBe($credential->secret_ciphertext);
    }
    expect(DB::selectOne("SELECT count(*) AS count FROM pg_constraint WHERE conname LIKE 'ai3c_%' AND NOT convalidated")->count)->toBe(0);
});

test('preexisting legacy attempts and monetary windows upgrade and reconcile without rewriting history', function () {
    require_once __DIR__.'/../AiVerificationMigrationFixtures.php';
    $legacy = (string) Str::uuid();
    DB::table('assistant_provider_controls')->insert([
        'budget_id' => $legacy, 'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1',
    ]);
    $id = verificationMigrationAttempt($legacy, true);
    $attempt = (array) DB::table('assistant_provider_attempts')->find($id);
    $windows = DB::table('assistant_provider_windows')->where('budget_id', $legacy)->orderBy('id')->get()->all();
    $windowColumns = array_keys((array) $windows[0]);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect((array) DB::table('assistant_provider_attempts')->select(array_keys($attempt))->find($id))->toEqual($attempt)
        ->and(DB::table('assistant_provider_attempts')->find($id)->contract_version)->toBe('legacy');
    $budgets = verificationMigrationBudgets();
    TenantAiVerificationSchema::reconcile([
        ['legacy_budget_id' => $legacy, 'deployment_id' => $budgets['deployment'], 'usage_budget_id' => $budgets['shared']],
    ], fn () => activity()->event('assistant.ai.migration_reconciled')->withProperties(['schema' => 'gateway_v1', 'outcome' => 'reconciled'])->log('Synthetic populated upgrade rehearsal'));
    expect(DB::table('assistant_provider_windows')->select($windowColumns)->where('budget_id', $legacy)->orderBy('id')->get()->all())->toEqual($windows)
        ->and((array) DB::table('assistant_provider_attempts')->select(array_keys($attempt))->find($id))->toEqual($attempt)
        ->and(DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->count())->toBe(5)
        ->and(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(0)
        ->and(DB::table('assistant_provider_allocations')->count())->toBe(0);
});

test('each migration stage rolls back a simulated interruption without exposing partial schema', function () {
    foreach ([['metadata'], ['constraints', 'guards'], ['validation']] as $methods) {
        $before = DB::select("SELECT c.relname,a.attname,format_type(a.atttypid,a.atttypmod) AS type FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum");
        expect(fn () => DB::transaction(function () use ($methods) {
            foreach ($methods as $method) {
                DB::unprepared(TenantAiVerificationSchema::$method());
            }
            throw new RuntimeException('Injected interrupted migration');
        }))->toThrow(RuntimeException::class, 'Injected interrupted migration');
        expect(DB::select("SELECT c.relname,a.attname,format_type(a.atttypid,a.atttypmod) AS type FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname,a.attnum"))->toEqual($before);
        DB::transaction(function () use ($methods) {
            foreach ($methods as $method) {
                DB::unprepared(TenantAiVerificationSchema::$method());
            }
        });
    }
});

test('migration lock timeout is atomic and a released maintenance lock permits roll forward', function () {
    config(['database.connections.ai_schema_observer' => config('database.connections.pgsql')]);
    $observer = DB::connection('ai_schema_observer');
    $observer->beginTransaction();
    $observer->select('SELECT budget_id FROM assistant_provider_controls LIMIT 1');
    try {
        expect(fn () => DB::transaction(fn () => DB::unprepared(TenantAiVerificationSchema::metadata())))
            ->toThrow(PDOException::class, 'lock timeout');
        expect(DB::selectOne("SELECT count(*) AS count FROM information_schema.columns WHERE table_name='assistant_provider_controls' AND column_name='contract_version'")->count)->toBe(0);
    } finally {
        $observer->rollBack();
        DB::purge('ai_schema_observer');
    }
    DB::transaction(function () {
        DB::unprepared(TenantAiVerificationSchema::metadata());
        expect(DB::selectOne("SELECT count(*) AS count FROM pg_locks WHERE pid=pg_backend_pid() AND mode='AccessExclusiveLock' AND granted AND relation='assistant_provider_controls'::regclass")->count)->toBe(1);
        expect(DB::selectOne('SHOW lock_timeout')->lock_timeout)->toBe('250ms')
            ->and(DB::selectOne('SHOW statement_timeout')->statement_timeout)->toBe('10s');
    });
    DB::transaction(function () {
        DB::unprepared(TenantAiVerificationSchema::constraints());
        DB::unprepared(TenantAiVerificationSchema::guards());
    });
    DB::transaction(fn () => DB::unprepared(TenantAiVerificationSchema::validation()));
    expect(DB::selectOne("SELECT count(*) AS count FROM pg_constraint WHERE conname LIKE 'ai3c_%' AND NOT convalidated")->count)->toBe(0);
});

test('M1 intermediate schema keeps all successor branches closed until integrity installation', function () {
    require_once __DIR__.'/../AiVerificationMigrationFixtures.php';
    DB::transaction(fn () => DB::unprepared(TenantAiVerificationSchema::metadata()));
    expect(DB::selectOne("SELECT count(*) AS count FROM pg_constraint WHERE conname LIKE 'ai3c_stage_closed_%' AND convalidated")->count)->toBe(4);
    expect(fn () => DB::transaction(fn () => verificationMigrationBudgets()))
        ->toThrow(PDOException::class, 'ai3c_stage_closed_1');
    expect(DB::table('assistant_provider_controls')->where('contract_version', 'gateway_v1')->count())->toBe(0);
    DB::transaction(function () {
        DB::unprepared(TenantAiVerificationSchema::constraints());
        DB::unprepared(TenantAiVerificationSchema::guards());
    });
    DB::transaction(fn () => DB::unprepared(TenantAiVerificationSchema::validation()));
    expect(DB::selectOne("SELECT count(*) AS count FROM pg_constraint WHERE conname LIKE 'ai3c_stage_closed_%'")->count)->toBe(0);
});

test('controlled reconciliation commits bounded audit and repeated execution preserves liability', function () {
    require_once __DIR__.'/../AiVerificationMigrationFixtures.php';
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $budgets = DB::transaction(function () {
        $budgets = verificationMigrationBudgets();
        verificationMigrationAttempt($budgets['legacy'], true);

        return $budgets;
    });
    $manifest = [['legacy_budget_id' => $budgets['legacy'], 'deployment_id' => $budgets['deployment'], 'usage_budget_id' => $budgets['shared']]];
    $audit = fn () => activity()->event('assistant.ai.migration_reconciled')->withProperties(['schema' => 'gateway_v1', 'outcome' => 'reconciled'])->log('Synthetic migration rehearsal');
    $originalWindows = DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson();
    expect(fn () => TenantAiVerificationSchema::reconcile([], $audit))
        ->toThrow(PDOException::class, 'AI reconciliation mapping required');
    expect(fn () => TenantAiVerificationSchema::reconcile($manifest, fn () => null))
        ->toThrow(RuntimeException::class, 'Required audit was not persisted.');
    expect(DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson())->toBe($originalWindows)
        ->and(DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->count())->toBe(0);
    TenantAiVerificationSchema::reconcile($manifest, $audit);
    $before = DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson();
    TenantAiVerificationSchema::reconcile($manifest, $audit);
    expect(DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('activity_log')->where('event', 'assistant.ai.migration_reconciled')->count())->toBe(2);
    $beforeAudit = DB::table('activity_log')->orderBy('id')->get()->toJson();
    expect(fn () => TenantAiVerificationSchema::reconcile($manifest, fn () => null))
        ->toThrow(RuntimeException::class, 'Required audit was not persisted.');
    expect(DB::table('assistant_provider_windows')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('activity_log')->orderBy('id')->get()->toJson())->toBe($beforeAudit);
});
