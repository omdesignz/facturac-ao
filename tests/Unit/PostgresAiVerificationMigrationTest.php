<?php

use App\Fiscal\TenantAiVerificationSchema;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql', 'ai-verification-schema');
require_once __DIR__.'/../AiVerificationMigrationFixtures.php';

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('PostgreSQL migration rehearsal required.');
    }
    expect(app()->environment())->toBe('testing')
        ->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['facturac_test_phase7b3c1_r3_utf8', 'facturac_test_phase7b3c1_r3_wrapped_utf8']);
    DB::beginTransaction();
    expect(DB::selectOne('SHOW server_encoding')->server_encoding)->toBe('UTF8');
});

test('legacy admission retains exact estimate byte and reserve boundaries', function (array $override, bool $valid) {
    $budgets = verificationMigrationBudgets();
    if ($valid) {
        $id = verificationMigrationAttempt($budgets['legacy'], false, $override);
        expect(DB::table('assistant_provider_attempts')->find($id)->contract_version)->toBe('legacy');
    } else {
        $before = verificationMigrationSnapshot();
        expect(fn () => DB::transaction(fn () => verificationMigrationAttempt($budgets['legacy'], false, $override)))
            ->toThrow(PDOException::class, 'assistant_provider_attempts_values');
        expect(verificationMigrationSnapshot())->toEqual($before);
    }
})->with([
    [['estimated_tokens' => null], false], [['estimated_tokens' => 1023], false],
    [['estimated_tokens' => 1024], true], [['estimated_tokens' => 8192], true], [['estimated_tokens' => 8193], false],
    [['request_bytes' => 0], false], [['request_bytes' => 1], true], [['request_bytes' => 7168], true], [['request_bytes' => 7169], false],
    [['reserved_micro_usd' => 552815], false], [['reserved_micro_usd' => 552817], false],
    [['workspace_public_id' => (new Workspace)->newUniqueId()], false],
]);

test('all legacy outcomes reconcile without rewriting monetary or consent history', function (string $state, ?string $outcome) {
    $budgets = verificationMigrationBudgets();
    $id = verificationMigrationAttempt($budgets['legacy'], true);
    if ($state !== 'admitted') {
        $actual = in_array($outcome, ['received', 'failed', 'profile_mismatch'], true);
        DB::table('assistant_provider_attempts')->where('id', $id)->update([
            'state' => $state, 'outcome' => $outcome, 'finalized_at' => '2026-10-09 12:00:01+00',
            'input_tokens' => $actual ? 100 : null, 'output_tokens' => $actual ? 10 : null, 'actual_micro_usd' => $actual ? 7 : null,
        ]);
        DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->update([
            'actual_input_tokens' => $actual ? 100 : 0, 'actual_output_tokens' => $actual ? 10 : 0,
            'actual_micro_usd' => $actual ? 7 : 0, 'unknown_usage_count' => $state === 'usage_unknown' ? 1 : 0,
        ]);
    }
    $before = verificationMigrationSnapshot();
    verificationMigrationReconcile($budgets);
    $after = verificationMigrationSnapshot();
    foreach (['assistant_provider_attempts', 'assistant_provider_controls', 'tenant_ai_credentials', 'activity_log'] as $table) {
        expect($after[$table])->toEqual($before[$table]);
    }
    foreach (DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->get() as $window) {
        expect($window->reserved_attempt_units)->toBe(1)->and($window->reserved_output_units)->toBe(1024)
            ->and($window->reserved_micro_usd)->toBe(0)->and($window->actual_micro_usd)->toBe(0);
    }
})->with([['admitted', null], ['received', 'received'], ['failed', 'failed'], ['failed', 'not_sent'], ['failed', 'profile_mismatch'], ['usage_unknown', 'usage_unknown']]);

test('reconciliation preserves UTC year leap and month edges across independent deployments', function (string $instant) {
    $date = CarbonImmutable::parse($instant)->utc();
    $maps = [];
    $actor = null;
    foreach (range(1, 2) as $index) {
        $budgets = verificationMigrationBudgets();
        $overrides = ['admitted_at' => $date->format('Y-m-d H:i:s.uP'), 'day_start' => $date->startOfDay()->format('Y-m-d H:i:s.uP'), 'month_start' => $date->startOfMonth()->format('Y-m-d H:i:s.uP')];
        if ($actor !== null) {
            $overrides['actor_attribution_id'] = $actor;
        }
        $id = verificationMigrationAttempt($budgets['legacy'], true, $overrides);
        $actor = DB::table('assistant_provider_attempts')->find($id)->actor_attribution_id;
        $maps[] = ['legacy_budget_id' => $budgets['legacy'], 'deployment_id' => $budgets['deployment'], 'usage_budget_id' => $budgets['shared']];
    }
    verificationMigrationReconcile($budgets, $maps);
    foreach ($maps as $map) {
        $windows = DB::table('assistant_provider_windows')->where('budget_id', $map['usage_budget_id'])->get();
        expect($windows)->toHaveCount(5);
        foreach ($windows as $window) {
            $month = str_ends_with($window->scope, 'month');
            $start = $month ? $date->startOfMonth() : $date->startOfDay();
            expect(CarbonImmutable::parse($window->window_start)->equalTo($start))->toBeTrue()
                ->and(CarbonImmutable::parse($window->window_end)->equalTo($month ? $start->addMonth() : $start->addDay()))->toBeTrue()
                ->and($window->reserved_attempt_units)->toBe(1)->and($window->reserved_output_units)->toBe(1024);
        }
    }
})->with(['2024-02-29T23:59:59.999999Z', '2025-12-31T23:59:59.999999Z', '2026-01-01T00:00:00Z', '2026-11-01T00:30:00+01:00']);

afterEach(function () {
    if (getenv('FISCAL_PG_GATE') === '1') {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge();
    }
});

test('corrected zero-use destination preflight leaves all persistent rows untouched', function () {
    $budgets = verificationMigrationBudgets();
    $before = verificationMigrationSnapshot();
    verificationMigrationReconcile($budgets);
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('corrected positive history reaches locked destination writes and conserves legacy counters', function () {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    $before = DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->orderBy('id')->get()->all();
    verificationMigrationReconcile($budgets);
    expect(DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->orderBy('id')->get()->all())->toEqual($before);
    $shared = DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->get();
    expect($shared)->toHaveCount(5);
    foreach ($shared as $window) {
        expect($window->reserved_attempt_units)->toBe(1)->and($window->reserved_output_units)->toBe(1024)
            ->and($window->reserved_micro_usd)->toBe(0);
    }
    expect(DB::table('assistant_provider_allocations')->count())->toBe(0);
});

test('unmapped surviving attempt is independently refused without any retained windows', function () {
    $budgets = verificationMigrationBudgets();
    $other = (string) Str::uuid();
    DB::table('assistant_provider_controls')->insert(['budget_id' => $other, 'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1']);
    verificationMigrationAttempt($other, false);
    expect(DB::table('assistant_provider_windows')->count())->toBe(0);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI incomplete liability mapping');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('every unexplained retained counter is refused without attempts or mapping', function (string $counter) {
    $budgets = verificationMigrationBudgets();
    $orphan = (string) Str::uuid();
    DB::table('assistant_provider_controls')->insert(['budget_id' => $orphan, 'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1']);
    DB::table('assistant_provider_windows')->insert([
        'budget_id' => $orphan, 'scope' => 'deployment_day', 'scope_key' => 'deployment',
        'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00', $counter => 1,
    ]);
    expect(DB::table('assistant_provider_attempts')->count())->toBe(0);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI incomplete liability mapping');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['reserved_micro_usd', 'attempt_count', 'actual_input_tokens', 'actual_output_tokens',
    'actual_micro_usd', 'unknown_usage_count', 'reserved_attempt_units', 'reserved_output_units']);

test('deleted history with retained windows refuses even with a mapping', function () {
    $budgets = verificationMigrationBudgets();
    $id = verificationMigrationAttempt($budgets['legacy'], true);
    DB::table('assistant_provider_attempts')->where('id', $id)->delete();
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI legacy liability reconciliation required');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('original multi-counter orphan refuses before any valid destination can be written', function (bool $mapped) {
    $valid = verificationMigrationBudgets();
    verificationMigrationAttempt($valid['legacy'], true);
    $orphan = verificationMigrationBudgets();
    DB::table('assistant_provider_windows')->insert([
        'budget_id' => $orphan['legacy'], 'scope' => 'deployment_day', 'scope_key' => 'deployment',
        'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00',
        'reserved_micro_usd' => 552816, 'attempt_count' => 1, 'unknown_usage_count' => 1,
    ]);
    $manifest = [['legacy_budget_id' => $valid['legacy'], 'deployment_id' => $valid['deployment'], 'usage_budget_id' => $valid['shared']]];
    if ($mapped) {
        $manifest[] = ['legacy_budget_id' => $orphan['legacy'], 'deployment_id' => $orphan['deployment'], 'usage_budget_id' => $orphan['shared']];
    }
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($valid, $manifest)))
        ->toThrow(PDOException::class, $mapped ? 'AI legacy liability reconciliation required' : 'AI incomplete liability mapping');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([false, true]);

test('inconsistent existing shared windows refuse before any accounting write', function (array $overrides, string $error) {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    DB::table('assistant_provider_windows')->insert([
        'budget_id' => $budgets['shared'], 'scope' => 'deployment_day', 'scope_key' => 'deployment',
        'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00', ...$overrides,
    ]);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))->toThrow(PDOException::class, $error);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([
    [['reserved_attempt_units' => 2], 'AI shared usage requires reconciliation'],
    [['reserved_output_units' => 1025], 'AI shared usage requires reconciliation'],
    [['actual_micro_usd' => 1], 'AI shared usage requires reconciliation'],
    [['window_end' => '2026-10-11 00:00:00+00'], 'AI shared usage requires reconciliation'],
    [['scope_key' => 'unexplained', 'reserved_attempt_units' => 1], 'AI unexplained shared usage'],
]);

test('isolated corrupt-restore simulation is refused by preflight and restores all constraints', function (string $counter, mixed $invalid) {
    $budgets = verificationMigrationBudgets();
    $before = verificationMigrationSnapshot();
    $constraints = DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='assistant_provider_windows'::regclass ORDER BY conname");
    DB::beginTransaction();
    try {
        // Only this guarded disposable transaction simulates a corrupt restored database.
        DB::statement('ALTER TABLE assistant_provider_windows DROP CONSTRAINT assistant_provider_windows_values, DROP CONSTRAINT ai3c_window_units_ck');
        DB::statement('ALTER TABLE assistant_provider_windows ALTER COLUMN '.$counter.' DROP NOT NULL');
        DB::table('assistant_provider_windows')->insert([
            'budget_id' => $budgets['legacy'], 'scope' => 'deployment_day', 'scope_key' => 'deployment',
            'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00', $counter => $invalid,
        ]);
        $corrupt = verificationMigrationSnapshot();
        expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
            ->toThrow(PDOException::class, 'AI corrupt retained counters');
        expect(verificationMigrationSnapshot())->toEqual($corrupt);
    } finally {
        DB::rollBack();
    }
    expect(verificationMigrationSnapshot())->toEqual($before)
        ->and(DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='assistant_provider_windows'::regclass ORDER BY conname"))->toEqual($constraints);
    expect(DB::selectOne("SELECT count(*) AS count FROM information_schema.columns WHERE table_name='assistant_provider_windows' AND is_nullable='YES'")->count)->toBe(0);
})->with(['reserved_micro_usd', 'attempt_count', 'actual_input_tokens', 'actual_output_tokens',
    'actual_micro_usd', 'unknown_usage_count', 'reserved_attempt_units', 'reserved_output_units'])->with([null, -1]);

test('reviewed schema accepts one structurally bound active fixture without runtime authority', function () {
    $fixture = verificationMigrationActiveFixture();
    expect(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->count())->toBe(1);
});

test('real model public identities persist and bind without transformation', function () {
    $fixture = verificationMigrationActiveFixture();
    $attempt = DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    foreach (['workspace' => 'workspaces', 'legal_entity' => 'legal_entities'] as $field => $table) {
        $stored = DB::table($table)->where('id', $attempt->{$field.'_id'})->value('public_id');
        expect($stored)->toMatch('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/')
            ->and($attempt->{$field.'_public_id'})->toBe($stored);
    }
    DB::statement('SELECT public.ai3c_check_attempt(?::uuid)', [$fixture['attempt']]);
});

test('noncanonical snapshot is rejected at the real PostgreSQL shape boundary', function (string $field, string $shape) {
    $model = $field === 'workspace_public_id' ? new Workspace : new LegalEntity;
    $id = $model->newUniqueId();
    $invalid = match ($shape) {
        'uppercase' => strtoupper($id),
        'overflow' => '8'.substr($id, 1),
        'short' => substr($id, 1),
        'long' => $id.'a',
        'invalid_character' => substr($id, 0, 25).'i',
        'separator' => substr($id, 0, 25).'-',
        'empty' => '',
        'null' => null,
    };
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture([$field => $invalid], [], $shape === 'uppercase' ? $field : null)))
        ->toThrow(PDOException::class, $shape === 'long' ? 'value too long for type character(26)' : 'assistant_provider_attempts_values');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['workspace_public_id', 'legal_entity_public_id'])
    ->with(['uppercase', 'overflow', 'short', 'long', 'invalid_character', 'separator', 'empty', 'null']);

test('canonical but substituted snapshot is rejected by the exact deferred binding', function (string $field) {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture([$field => (new LegalEntity)->newUniqueId()])))
        ->toThrow(PDOException::class, 'AI binding mismatch');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['workspace_public_id', 'legal_entity_public_id']);

test('credential provider and profile snapshots cannot substitute signed relationships', function (array $override, string $error) {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture($override)))
        ->toThrow(PDOException::class, $error);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([
    [['credential_version_number' => 2], 'Invalid AI verification relationship'],
    [['credential_created_at' => '2000-01-01 00:00:00+00'], 'Invalid AI verification relationship'],
    [['provider_key' => 'other-provider'], 'AI binding mismatch'],
    [['profile_manifest_sha256' => str_repeat('0', 64)], 'AI binding mismatch'],
    [['endpoint_policy_key' => 'other-endpoint'], 'AI binding mismatch'],
]);

test('observed model must match the immutable approved profile', function () {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture(finalOverrides: ['observed_model_id' => 'other-model'])))
        ->toThrow(PDOException::class, 'Invalid AI observed model');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('approved corrected receipt bytes and all signed positions reproduce without regeneration', function () {
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-1-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['canonicalization']['field_order'])->toHaveCount(47);
    foreach ($evidence['canonicalization']['vectors'] as $vector) {
        $prefix = hex2bin($vector['domain_prefix_hex']);
        $json = json_encode($vector['array'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $bytes = $prefix.$json;
        expect($json)->toBe($vector['canonical_json'])
            ->and(strlen($bytes))->toBe($vector['bytes_length'])
            ->and(hash('sha256', $bytes))->toBe($vector['sha256'])
            ->and(hash_hmac('sha256', $bytes, str_repeat(chr(0), 32)))->toBe($vector['hmac_sha256_with_public_zero_test_key']);
        foreach ($vector['array'] as $position => $value) {
            $changed = $vector['array'];
            $changed[$position] = in_array($position, [6, 7], true) ? strtoupper($value) : 'tampered';
            $mutated = $prefix.json_encode($changed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            expect(hash_equals($vector['hmac_sha256_with_public_zero_test_key'], hash_hmac('sha256', $mutated, str_repeat(chr(0), 32))))->toBeFalse();
        }
    }
});

test('active retirement requires destruction and advancing connection generation', function () {
    $fixture = verificationMigrationActiveFixture();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    DB::table('tenant_ai_credentials')->where('id', $fixture['credential'])->update([
        'state' => 'revoked', 'revoked_at' => '2026-10-09 12:00:03+00',
        'secret_destroyed_at' => '2026-10-09 12:00:03+00',
        'secret_ciphertext' => null, 'wrapped_dek' => null, 'kek_version' => null,
    ]);
    DB::table('tenant_ai_connections')->where('id', $fixture['connection'])->update(['active_generation' => 2, 'revision' => 4]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(DB::table('tenant_ai_credentials')->where('id', $fixture['credential'])->value('state'))->toBe('revoked');
});

test('schema rotation atomically retires A and binds B while preserving evidence', function () {
    $fixture = verificationMigrationActiveFixture();
    $originalAttempt = DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    $candidate = verificationMigrationRotate($fixture);
    expect(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->pluck('id')->all())->toBe([$candidate])
        ->and(DB::table('tenant_ai_credentials')->find($fixture['credential'])->secret_ciphertext)->toBeNull()
        ->and(DB::table('assistant_provider_attempts')->find($fixture['attempt']))->toEqual($originalAttempt);
});

test('persisted allocation window identity cannot be substituted after receipt finalization', function () {
    $fixture = verificationMigrationActiveFixture();
    $allocation = DB::table('assistant_provider_allocations as a')
        ->join('assistant_provider_windows as w', 'w.id', '=', 'a.window_id')
        ->where('a.attempt_id', $fixture['attempt'])->where('a.role', 'shared_usage')
        ->where('w.scope', 'workspace_day')->select('w.id')->first();
    $foreign = User::factory()->withWorkspace()->create();
    expect(fn () => DB::transaction(function () use ($allocation, $foreign) {
        DB::table('assistant_provider_windows')->where('id', $allocation->id)
            ->update(['scope_key' => (string) $foreign->current_workspace_id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(PDOException::class);
});

test('reconciliation failure after writes restores the complete prior persistent state', function () {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(function () use ($budgets) {
        verificationMigrationReconcile($budgets);
        expect(DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->count())->toBe(5);
        throw new RuntimeException('Injected pre-commit audit failure');
    }))->toThrow(RuntimeException::class, 'Injected pre-commit audit failure');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('schema rotation rollback restores the original active credential and evidence', function () {
    $fixture = verificationMigrationActiveFixture();
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(function () use ($fixture) {
        verificationMigrationRotate($fixture);
        throw new RuntimeException('Injected pre-commit audit failure');
    }))->toThrow(RuntimeException::class, 'Injected pre-commit audit failure');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('retained schema evidence refuses downgrade without dropping data', function () {
    verificationMigrationActiveFixture();
    $before = verificationMigrationSnapshot();
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-1-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(fn () => DB::transaction(fn () => DB::unprepared($evidence['sql_listings'][5]['sql'])))
        ->toThrow(PDOException::class, 'AI downgrade refused: retained data');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('downgrade refuses legacy precision loss and new unit counters independently', function (string $retained) {
    $legacy = (string) Str::uuid();
    DB::table('assistant_provider_controls')->insert(['budget_id' => $legacy, 'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1']);
    verificationMigrationAttempt($legacy, true, $retained === 'precision' ? ['admitted_at' => '2026-10-09 12:00:00.000001+00'] : []);
    if ($retained !== 'precision') {
        DB::table('assistant_provider_windows')->where('budget_id', $legacy)->update([$retained => 1]);
    }
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => DB::unprepared(TenantAiVerificationSchema::downPreflight())))
        ->toThrow(PDOException::class, $retained === 'precision' ? 'AI downgrade refused: precision loss' : 'AI downgrade refused: retained data');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['precision', 'reserved_attempt_units', 'reserved_output_units']);

test('persisted receipt terminal fields and allocations cannot be rewritten or deleted', function (string $operation) {
    $fixture = verificationMigrationActiveFixture();
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(function () use ($fixture, $operation) {
        match ($operation) {
            'attempt_delete' => DB::table('assistant_provider_attempts')->where('id', $fixture['attempt'])->delete(),
            'receipt_mac' => DB::table('assistant_provider_attempts')->where('id', $fixture['attempt'])->update(['receipt_mac' => str_repeat('a', 64)]),
            'snapshot' => DB::table('assistant_provider_attempts')->where('id', $fixture['attempt'])->update(['workspace_public_id' => (new Workspace)->newUniqueId()]),
            'allocation_delete' => DB::table('assistant_provider_allocations')->where('attempt_id', $fixture['attempt'])->delete(),
            'allocation_update' => DB::table('assistant_provider_allocations')->where('attempt_id', $fixture['attempt'])->update(['reserved_output_units' => 1]),
        };
    }))->toThrow(PDOException::class);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['attempt_delete', 'receipt_mac', 'snapshot', 'allocation_delete', 'allocation_update']);

test('final receipt requires its complete provenance and activation binding', function (string $field) {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture([], [$field => null])))
        ->toThrow(PDOException::class);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['observed_at', 'send_authorized_at', 'claim_strength', 'observed_organization_id',
    'observed_workspace_id', 'observed_model_id', 'evidence_id', 'receipt_key_id', 'receipt_mac',
    'promotion_expires_at', 'promotion_disposition', 'committed_activated_generation']);

test('PostgreSQL rejects a second active credential even when connection is disabled', function () {
    $fixture = verificationMigrationActiveFixture();
    $old = (array) DB::table('tenant_ai_credentials')->find($fixture['credential']);
    $candidate = $old;
    $candidate['id'] = (string) Str::uuid();
    $candidate['version_number'] = 2;
    $candidate['state'] = 'pending';
    $candidate['verification_state'] = 'unverified';
    foreach (['activated_generation', 'verified_profile_id', 'verification_operation_id', 'last_verified_at',
        'last_test_operation_id', 'last_tested_at', 'last_test_outcome'] as $field) {
        $candidate[$field] = null;
    }
    DB::table('tenant_ai_credentials')->insert($candidate);
    expect(DB::table('tenant_ai_connections')->find($fixture['connection'])->disabled)->toBeTrue();
    expect(fn () => DB::transaction(function () use ($candidate, $old) {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('tenant_ai_credentials')->where('id', $candidate['id'])->update([
            'state' => 'active', 'verification_state' => 'verified', 'activated_generation' => 2,
            'verified_profile_id' => $old['verified_profile_id'], 'verification_operation_id' => (string) Str::uuid(),
            'last_verified_at' => $old['last_verified_at'],
        ]);
    }))->toThrow(PDOException::class, 'tenant_ai_credentials_one_active');
    expect(DB::table('tenant_ai_credentials')->where('connection_id', $fixture['connection'])->where('state', 'active')->count())->toBe(1);
});

test('independent connections retain their own active credentials', function () {
    $one = verificationMigrationActiveFixture();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    $two = verificationMigrationActiveFixture();
    expect($one['connection'])->not->toBe($two['connection'])
        ->and(DB::table('tenant_ai_credentials')->where('state', 'active')->count())->toBe(2);
});

test('zero-liability orphan windows remain neutral without a mapping', function () {
    $budgets = verificationMigrationBudgets();
    $orphan = (string) Str::uuid();
    DB::table('assistant_provider_controls')->insert(['budget_id' => $orphan, 'profile' => 'p7-anthropic-haiku55-us-2026-10-08-v1', 'policy' => 'p7-question-v1']);
    DB::table('assistant_provider_windows')->insert(['budget_id' => $orphan, 'scope' => 'deployment_day', 'scope_key' => 'deployment',
        'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00']);
    $before = verificationMigrationSnapshot();
    verificationMigrationReconcile($budgets);
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('reconciliation rejects duplicate or substituted mapping without repairing source history', function (string $kind) {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    $row = ['legacy_budget_id' => $budgets['legacy'], 'deployment_id' => $budgets['deployment'], 'usage_budget_id' => $budgets['shared']];
    $manifest = match ($kind) {
        'duplicate' => [$row, $row],
        'missing_root' => [[...$row, 'deployment_id' => (string) Str::uuid()]],
        'missing_source' => [[...$row, 'legacy_budget_id' => (string) Str::uuid()]],
        'wrong_destination' => [[...$row, 'usage_budget_id' => $budgets['legacy']]],
    };
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets, $manifest)))->toThrow(PDOException::class);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['duplicate', 'missing_root', 'missing_source', 'wrong_destination']);

test('unexplained mapped source actual totals are refused independently', function (string $counter) {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->increment($counter);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI legacy liability reconciliation required');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['actual_input_tokens', 'actual_output_tokens', 'actual_micro_usd', 'unknown_usage_count', 'reserved_attempt_units', 'reserved_output_units']);

test('incorrect retained window identities refuse before destination accounting writes', function (array $windowOverride) {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true, windowOverrides: $windowOverride);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI legacy liability reconciliation required');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([
    [['scope' => 'workspace_month']], [['scope_key' => 'foreign-scope-key']],
    [['window_start' => '2026-09-01 00:00:00+00']], [['window_end' => '2026-12-01 00:00:00+00']],
]);

test('partial retained attempt history cannot reduce mapped liability', function () {
    $budgets = verificationMigrationBudgets();
    verificationMigrationAttempt($budgets['legacy'], true);
    verificationMigrationAttempt($budgets['legacy'], false);
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
        ->toThrow(PDOException::class, 'AI legacy liability reconciliation required');
    expect(verificationMigrationSnapshot())->toEqual($before);
});

test('multiple source budgets conserve shared totals and conflicting destinations refuse', function (bool $conflicting) {
    $first = verificationMigrationBudgets();
    $second = verificationMigrationBudgets();
    verificationMigrationAttempt($first['legacy'], true);
    verificationMigrationAttempt($second['legacy'], true);
    $manifest = [
        ['legacy_budget_id' => $first['legacy'], 'deployment_id' => $first['deployment'], 'usage_budget_id' => $first['shared']],
        ['legacy_budget_id' => $second['legacy'], 'deployment_id' => $first['deployment'], 'usage_budget_id' => $conflicting ? $second['shared'] : $first['shared']],
    ];
    if ($conflicting) {
        $before = verificationMigrationSnapshot();
        expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($first, $manifest)))
            ->toThrow(PDOException::class, 'AI reconciliation mapping required');
        expect(verificationMigrationSnapshot())->toEqual($before);
    } else {
        verificationMigrationReconcile($first, $manifest);
        foreach (['deployment_month', 'deployment_day'] as $scope) {
            $window = DB::table('assistant_provider_windows')->where('budget_id', $first['shared'])->where('scope', $scope)->first();
            expect($window->reserved_attempt_units)->toBe(2)->and($window->reserved_output_units)->toBe(2048)
                ->and($window->reserved_micro_usd)->toBe(0);
        }
    }
})->with([false, true]);

test('all retained counters reject null and negative values at the real schema boundary', function (string $counter, mixed $invalid) {
    $budgets = verificationMigrationBudgets();
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => DB::table('assistant_provider_windows')->insert([
        'budget_id' => $budgets['legacy'], 'scope' => 'deployment_day', 'scope_key' => 'deployment',
        'window_start' => '2026-10-09 00:00:00+00', 'window_end' => '2026-10-10 00:00:00+00', $counter => $invalid,
    ])))->toThrow(PDOException::class);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(['reserved_micro_usd', 'attempt_count', 'actual_input_tokens', 'actual_output_tokens',
    'actual_micro_usd', 'unknown_usage_count', 'reserved_attempt_units', 'reserved_output_units'])->with([null, -1]);

test('checked numeric aggregation preserves bigint maximum and rejects overflow atomically', function (bool $overflow) {
    $budgets = verificationMigrationBudgets();
    $attempt = verificationMigrationAttempt($budgets['legacy'], true);
    DB::table('assistant_provider_attempts')->where('id', $attempt)->update([
        'state' => 'received', 'outcome' => 'received', 'finalized_at' => '2026-10-09 12:00:01+00',
        'input_tokens' => 0, 'output_tokens' => 0, 'actual_micro_usd' => '9223372036854775807',
    ]);
    DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->update(['actual_micro_usd' => '9223372036854775807']);
    if ($overflow) {
        $second = verificationMigrationAttempt($budgets['legacy'], false);
        DB::table('assistant_provider_attempts')->where('id', $second)->update([
            'state' => 'received', 'outcome' => 'received', 'finalized_at' => '2026-10-09 12:00:01+00',
            'input_tokens' => 0, 'output_tokens' => 0, 'actual_micro_usd' => 1,
        ]);
        $before = verificationMigrationSnapshot();
        expect(fn () => DB::transaction(fn () => verificationMigrationReconcile($budgets)))
            ->toThrow(PDOException::class, 'bigint out of range');
        expect(verificationMigrationSnapshot())->toEqual($before);
    } else {
        verificationMigrationReconcile($budgets);
        expect((string) DB::table('assistant_provider_windows')->where('budget_id', $budgets['legacy'])->value('actual_micro_usd'))
            ->toBe('9223372036854775807')
            ->and(DB::table('assistant_provider_windows')->where('budget_id', $budgets['shared'])->value('reserved_output_units'))->toBe(1024);
    }
})->with([false, true]);

test('schema rotation supports an atomically updated selected credential', function () {
    $fixture = verificationMigrationActiveFixture();
    $attempt = DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    DB::table('tenant_ai_settings')->where('workspace_id', $attempt->workspace_id)->update([
        'mode' => 'customer_managed', 'profile_id' => $attempt->profile_id,
        'connection_id' => $fixture['connection'], 'credential_version_id' => $fixture['credential'], 'revision' => 2,
    ]);
    $candidate = verificationMigrationRotate($fixture, true);
    expect(DB::table('tenant_ai_settings')->where('workspace_id', $attempt->workspace_id)->value('credential_version_id'))->toBe($candidate);
});

test('a real foreign entity cannot be substituted into the exact admitted context', function () {
    $foreignUser = User::factory()->withWorkspace()->create();
    $foreign = LegalEntity::factory()->create(['workspace_id' => $foreignUser->current_workspace_id]);
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture([
        'legal_entity_id' => $foreign->id, 'legal_entity_public_id' => $foreign->public_id,
    ])))->toThrow(PDOException::class, 'assistant_provider_context_fk');
});

test('real stored public identities enter signed material byte for byte', function () {
    $fixture = verificationMigrationActiveFixture();
    $attempt = DB::table('assistant_provider_attempts')->find($fixture['attempt']);
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-1-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    $fields = $evidence['canonicalization']['vectors'][0]['array'];
    $fields[6] = $attempt->workspace_public_id;
    $fields[7] = $attempt->legal_entity_public_id;
    $encoded = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded[6])->toBe(DB::table('workspaces')->where('id', $attempt->workspace_id)->value('public_id'))
        ->and($decoded[7])->toBe(DB::table('legal_entities')->where('id', $attempt->legal_entity_id)->value('public_id'));
    $original = hash_hmac('sha256', $encoded, str_repeat(chr(0), 32));
    $fields[6] = strtoupper($fields[6]);
    expect(hash_equals($original, hash_hmac('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), str_repeat(chr(0), 32))))->toBeFalse();
});

test('every one of the nine original allocations is required at deferred validation', function (int $missing) {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture(missingAllocation: $missing)))
        ->toThrow(PDOException::class, 'Incomplete AI allocation set');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with(range(0, 8));

test('duplicate or extra allocation cannot extend the signed allocation set', function (bool $duplicate) {
    $fixture = verificationMigrationActiveFixture();
    $allocation = (array) DB::table('assistant_provider_allocations')->where('attempt_id', $fixture['attempt'])->first();
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(function () use ($allocation, $duplicate) {
        if (! $duplicate) {
            $window = (array) DB::table('assistant_provider_windows')->find($allocation['window_id']);
            unset($window['id']);
            $window['window_start'] = '2027-01-01 00:00:00+00';
            $window['window_end'] = '2027-02-01 00:00:00+00';
            $allocation['window_id'] = DB::table('assistant_provider_windows')->insertGetId($window);
        }
        DB::table('assistant_provider_allocations')->insert($allocation);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(PDOException::class, $duplicate ? 'ai3c_alloc_pk' : 'Incomplete AI allocation set');
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([false, true]);

test('allocation money units and timestamps must match the original admission', function (array $overrides, string $constraint) {
    $before = verificationMigrationSnapshot();
    expect(fn () => DB::transaction(fn () => verificationMigrationActiveFixture(allocationOverrides: $overrides)))
        ->toThrow(PDOException::class, $constraint);
    expect(verificationMigrationSnapshot())->toEqual($before);
})->with([
    [['reserved_micro_usd' => 1], 'ai3c_alloc_units_ck'],
    [['reserved_attempt_units' => 2], 'ai3c_alloc_units_ck'],
    [['reserved_output_units' => 2048], 'Incomplete AI allocation set'],
    [['created_at' => '2026-10-09 12:00:00.000001+00'], 'Incomplete AI allocation set'],
]);
