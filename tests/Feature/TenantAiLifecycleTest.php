<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiCatalogue;
use App\Fiscal\TenantAiLifecycle;
use App\Fiscal\TenantAiStorage;
use App\Models\TenantAiCredential;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

require_once __DIR__.'/../TenantAiLifecycleFixtures.php';

beforeEach(fn () => tenantAiLifecycleSetup($this));
afterEach(fn () => tenantAiLifecycleCleanup($this));

test('pending replacement destroys only the old envelope and preserves immutable attribution and disabled policy', function () {
    $f = tenantAiLifecycleFixture();
    $before = (array) DB::table('tenant_ai_credentials')->first();
    $settings = DB::table('tenant_ai_settings')->first();
    $meta = app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-replacement');
    $old = DB::table('tenant_ai_credentials')->where('id', $before['id'])->first();
    $new = DB::table('tenant_ai_credentials')->where('id', $meta['id'])->first();
    expect($old->state)->toBe('revoked')->and($old->secret_ciphertext)->toBeNull()->and($old->wrapped_dek)->toBeNull()->and($old->kek_version)->toBeNull()
        ->and($old->secret_destroyed_at)->not->toBeNull()->and($old->creator_attribution_id)->toBe($before['creator_attribution_id'])
        ->and($new->state)->toBe('pending')->and($new->verification_state)->toBe('unverified')->and((int) $new->version_number)->toBe(2)
        ->and((int) DB::table('tenant_ai_connections')->value('revision'))->toBe(2)
        ->and(DB::table('tenant_ai_settings')->first())->toEqual($settings)
        ->and(array_keys($meta))->toBe(['id', 'connection_id', 'state', 'verification_state', 'created_at', 'expires_at']);
    $revealed = false;
    expect(fn () => app(TenantAiStorage::class)->inspectForStorageTest($f['context'], $old->id, function () use (&$revealed) {
        $revealed = true;
    }))
        ->toThrow(TenantAiStorageUnavailable::class);
    expect($revealed)->toBeFalse();
    app(TenantAiStorage::class)->inspectForStorageTest($f['context'], $new->id, fn ($secret) => expect($secret)->toBe('synthetic-replacement'));
    $events = Activity::query()->where('event', 'like', 'assistant.ai.%')->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['assistant.ai.credential_configured', 'assistant.ai.credential_revoked', 'assistant.ai.credential_destroyed', 'assistant.ai.credential_configured'])
        ->and($events->skip(1)->pluck('properties.operation_id')->unique())->toHaveCount(1);
    foreach ($events->skip(1) as $event) {
        expect($event->properties->get('actor_attribution_id'))->toBe($f['user']->attribution_id)
            ->and($event->properties->get('old_connection_revision'))->toBe(1)->and($event->properties->get('new_connection_revision'))->toBe(2)
            ->and($event->properties->get('ip_address'))->toBeNull()->and($event->properties->get('user_agent'))->toBeNull();
    }
});

test('revocation is terminal immediate key independent and duplicate semantics preserve one transition', function () {
    $f = tenantAiLifecycleFixture();
    $f['request']->session()->forget('auth.password_confirmed_at');
    unlink($this->lifecycleKey);
    config(['tenant_ai.kek_root' => null]);
    $lifecycle = app(TenantAiLifecycle::class);
    $meta = $lifecycle->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1);
    expect($meta['state'])->toBe('revoked')->and(DB::table('tenant_ai_credentials')->value('secret_ciphertext'))->toBeNull();
    $snapshot = tenantAiLifecycleSnapshot();
    expect(fn () => $lifecycle->revoke($f['emergency'], $meta['connection_id'], $meta['id'], 1, 1))->toThrow(TenantAiStorageUnavailable::class);
    expect($lifecycle->revoke($f['emergency'], $meta['connection_id'], $meta['id'], 1, 2))->toBe($meta)
        ->and(tenantAiLifecycleSnapshot())->toBe($snapshot);
    expect(fn () => DB::transaction(fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['state' => 'pending', 'revoked_at' => null])))
        ->toThrow(PDOException::class);
});

test('a new candidate after revocation allocates a fresh generation without reviving the old identity', function () {
    $f = tenantAiLifecycleFixture();
    $l = app(TenantAiLifecycle::class);
    $l->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1);
    $new = $l->candidate($f['context'], $f['metadata']['connection_id'], 1, 2, null, 'synthetic-fresh');
    expect($new['id'])->not->toBe($f['metadata']['id'])->and(DB::table('tenant_ai_credentials')->where('id', $new['id'])->value('version_number'))->toBe(2)
        ->and(DB::table('tenant_ai_credentials')->where('state', 'revoked')->count())->toBe(1)
        ->and(DB::table('tenant_ai_connections')->value('revoked_at'))->toBeNull();
});

test('candidate and revoke reject stale revisions or wrong expected identity without changes', function (string $attack) {
    $f = tenantAiLifecycleFixture();
    $snapshot = tenantAiLifecycleSnapshot();
    $l = app(TenantAiLifecycle::class);
    $operation = match ($attack) {
        'settings' => fn () => $l->candidate($f['context'], $f['metadata']['connection_id'], 2, 1, $f['metadata']['id'], 'synthetic'),
        'connection' => fn () => $l->candidate($f['context'], $f['metadata']['connection_id'], 1, 2, $f['metadata']['id'], 'synthetic'),
        'absence' => fn () => $l->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, null, 'synthetic'),
        'identity' => fn () => $l->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, (string) Str::uuid(), 'synthetic'),
        'revoke-settings' => fn () => $l->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 2, 1),
        'revoke-connection' => fn () => $l->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 2),
        'revoke-identity' => fn () => $l->revoke($f['emergency'], $f['metadata']['connection_id'], (string) Str::uuid(), 1, 1),
    };
    expect($operation)->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($snapshot);
})->with(['settings', 'connection', 'absence', 'identity', 'revoke-settings', 'revoke-connection', 'revoke-identity']);

test('disablement clears selection without altering pending material or enabling another mode', function (string $target) {
    $f = tenantAiLifecycleFixture();
    if ($target === 'workspace') {
        DB::table('tenant_ai_settings')->update(['mode' => 'vap_managed', 'profile_id' => TenantAiCatalogue::ID, 'revision' => 2]);
    } else {
        DB::table('tenant_ai_connections')->update(['disabled' => false, 'revision' => 2]);
    }
    $credential = DB::table('tenant_ai_credentials')->get()->toJson();
    $f['request']->session()->forget('auth.password_confirmed_at');
    $l = app(TenantAiLifecycle::class);
    $run = $target === 'workspace' ? fn ($rev) => $l->disableWorkspace($f['emergency'], $rev)
        : fn ($rev) => $l->disableConnection($f['emergency'], $f['metadata']['connection_id'], 1, $rev);
    $run(2);
    $snapshot = tenantAiLifecycleSnapshot();
    expect(fn () => $run(2))->toThrow(TenantAiStorageUnavailable::class);
    $run(3);
    expect(tenantAiLifecycleSnapshot())->toBe($snapshot)->and(DB::table('tenant_ai_credentials')->get()->toJson())->toBe($credential)
        ->and(DB::table('tenant_ai_settings')->value('mode'))->toBe('disabled')->and(DB::table('tenant_ai_settings')->value('profile_id'))->toBeNull()
        ->and((bool) DB::table('tenant_ai_connections')->value('disabled'))->toBeTrue();
    $events = Activity::query()->where('event', 'like', 'assistant.ai.%')->orderBy('id')->pluck('event')->all();
    expect($events)->toBe($target === 'workspace' ? ['assistant.ai.credential_configured', 'assistant.ai.mode_changed', 'assistant.ai.selection_changed']
        : ['assistant.ai.credential_configured', 'assistant.ai.selection_changed']);
})->with(['workspace', 'connection']);

test('fresh emergency authority denies every non-owner or invalid web context', function (string $attack) {
    $f = tenantAiLifecycleFixture();
    match ($attack) {
        'admin' => WorkspaceMembership::where('id', $f['context']->membershipId)->update(['role' => 'admin']),
        'removed' => WorkspaceMembership::where('id', $f['context']->membershipId)->delete(),
        'inactive' => WorkspaceMembership::where('id', $f['context']->membershipId)->update(['is_active' => false]),
        'mfa' => $f['user']->forceFill(['two_factor_confirmed_at' => null])->save(),
        'email' => $f['user']->forceFill(['email_verified_at' => null])->save(),
        'session' => $f['request']->session()->forget((string) config('work_session.started_at_key')),
        'csrf' => $f['request']->headers->set('X-CSRF-TOKEN', 'wrong'),
        'method' => $f['request']->setMethod('GET'),
        'impersonation' => Context::add('impersonator_id', 1),
        'automation' => Context::add('automation_id', 1),
        'deployment' => config(['tenant_ai.deployment_id' => (string) Str::uuid()]),
        'auth' => auth('web')->logout(),
    };
    $snapshot = tenantAiLifecycleSnapshot();
    expect(fn () => app(TenantAiLifecycle::class)->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($snapshot);
})->with(['admin', 'removed', 'inactive', 'mfa', 'email', 'session', 'csrf', 'method', 'impersonation', 'automation', 'deployment', 'auth']);

test('emergency context cannot be substituted for candidate authority or serialized', function () {
    $f = tenantAiLifecycleFixture();
    $f['request']->session()->forget('auth.password_confirmed_at');
    expect(fn () => $f['context']->authorize())->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => serialize($f['emergency']))->toThrow(TenantAiStorageUnavailable::class)
        ->and(fn () => app(TenantAiLifecycle::class)->candidate($f['emergency'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic'))->toThrow(TypeError::class);
    expect($f['emergency']->authorize()->id)->toBe($f['user']->id);
});

test('lifecycle rejects foreign tenant targets with the same fixed error as missing targets', function (string $operation) {
    $foreign = tenantAiLifecycleFixture();
    $own = tenantAiLifecycleFixture();
    $l = app(TenantAiLifecycle::class);
    $snapshot = tenantAiLifecycleSnapshot();
    foreach ([$foreign['metadata']['connection_id'], (string) Str::uuid()] as $id) {
        try {
            match ($operation) {
                'candidate' => $l->candidate($own['context'], $id, 1, 1, $foreign['metadata']['id'], 'synthetic'),
                'revoke' => $l->revoke($own['emergency'], $id, $foreign['metadata']['id'], 1, 1),
                'disable' => $l->disableConnection($own['emergency'], $id, 1, 1),
            };
            $this->fail('Expected fixed refusal');
        } catch (TenantAiStorageUnavailable $error) {
            expect($error->getMessage())->toBe((new TenantAiStorageUnavailable)->getMessage())->and($error->getPrevious())->toBeNull();
        }
    }
    expect(tenantAiLifecycleSnapshot())->toBe($snapshot);
})->with(['candidate', 'revoke', 'disable']);

test('replacement rollback restores all state after each mandatory audit event fails', function (string $event) {
    $f = tenantAiLifecycleFixture();
    $snapshot = tenantAiLifecycleSnapshot();
    Activity::creating(function ($activity) use ($event) {
        if ($activity->event === 'assistant.ai.'.$event) {
            throw new RuntimeException('synthetic audit failure');
        }
    });
    expect(fn () => app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-next'))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($snapshot);
})->with(['credential_revoked', 'credential_destroyed', 'credential_configured']);

test('failed secret preparation never retires the previous candidate', function (string $attack) {
    $f = tenantAiLifecycleFixture();
    $snapshot = tenantAiLifecycleSnapshot();
    if ($attack === 'key') {
        config(['tenant_ai.kek_root' => null]);
    }
    expect(fn () => app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], $attack === 'format' ? "bad\nvalue" : 'synthetic'))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($snapshot);
})->with(['key', 'format']);

test('lifecycle errors outputs audits and query listeners cannot expose old or new envelope material', function () {
    $f = tenantAiLifecycleFixture();
    $old = (array) DB::table('tenant_ai_credentials')->first();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = [$query->sql, $query->bindings];
    });
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    try {
        $meta = app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'sentinel-new-lifecycle-secret');
        try {
            app(TenantAiLifecycle::class)->candidate($f['context'], $meta['connection_id'], 1, 1, $meta['id'], 'sentinel-failed-lifecycle-secret');
        } catch (TenantAiStorageUnavailable $error) {
            report($error);
            expect($error->getPrevious())->toBeNull();
        }
        $capture = json_encode([$meta, $queries, Activity::all()->toArray(), $handler->getRecords(), TenantAiCredential::find($meta['id'])->toArray()]);
        $new = (array) DB::table('tenant_ai_credentials')->where('id', $meta['id'])->first();
        foreach ([$f['secret'], 'sentinel-new-lifecycle-secret', 'sentinel-failed-lifecycle-secret', $old['secret_ciphertext'], $old['wrapped_dek'], $new['secret_ciphertext'], $new['wrapped_dek'], 'lifecycle-test'] as $marker) {
            expect($capture)->not->toContain($marker);
        }
    } finally {
        Log::getLogger()->popHandler();
    }
});

test('full verification claims and forbidden transitions remain rejected even when environment changes', function (string $state, string $environment) {
    $f = tenantAiLifecycleFixture();
    $snapshot = tenantAiLifecycleSnapshot();
    app()->instance('env', $environment);
    try {
        $values = match ($state) {
            'active' => ['state' => 'active', 'verification_state' => 'verified', 'verified_profile_id' => TenantAiCatalogue::ID, 'verification_operation_id' => (string) Str::uuid(), 'last_verified_at' => now()],
            'replaced' => ['state' => 'replaced', 'replaced_at' => now(), 'revoked_at' => now()],
            'failed' => ['verification_state' => 'failed'],
        };
        expect(fn () => DB::transaction(function () use ($values): void {
            DB::table('tenant_ai_credentials')->update($values);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }
        }))->toThrow(PDOException::class);
        expect(tenantAiLifecycleSnapshot())->toBe($snapshot);
    } finally {
        app()->instance('env', 'testing');
    }
})->with(['active', 'replaced', 'failed'])->with(['testing', 'production']);

test('no issuer consumer or gateway connection is introduced by lifecycle services', function () {
    $methods = array_map(fn ($m) => $m->name, (new ReflectionClass(TenantAiLifecycle::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods);
    expect($methods)->toBe(['__construct', 'candidate', 'disableConnection', 'disableWorkspace', 'revoke'])
        ->and(file_get_contents(app_path('Fiscal/TenantAiLifecycle.php')))->not->toContain('CredentialPromotionPermit');
    foreach (['VapAiGateway', 'LegacyAssistantAiGateway', 'GatewayAssistantPlanner', 'AnthropicIntentPlanner', 'AssistantProviderTransport', 'AssistantIntentGateway'] as $name) {
        expect(file_get_contents(app_path('Fiscal/'.$name.'.php')))->not->toContain('TenantAi');
    }
    expect(file_get_contents(app_path('Providers/AppServiceProvider.php')))->not->toContain('TenantAi')
        ->and(config('assistant.provider.enabled'))->toBeFalse()->and(config('assistant.provider.egress_enabled'))->toBeFalse();
});

test('revocation and disablement roll back when mandatory audit fails', function (string $operation) {
    $f = tenantAiLifecycleFixture();
    if ($operation === 'workspace') {
        DB::table('tenant_ai_settings')->update(['mode' => 'vap_managed', 'profile_id' => TenantAiCatalogue::ID, 'revision' => 2]);
    }
    if ($operation === 'connection') {
        DB::table('tenant_ai_connections')->update(['disabled' => false, 'revision' => 2]);
    }
    $before = tenantAiLifecycleSnapshot();
    Activity::creating(fn () => throw new RuntimeException('synthetic mandatory audit failure'));
    $l = app(TenantAiLifecycle::class);
    expect(fn () => match ($operation) {
        'revoke' => $l->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1),
        'workspace' => $l->disableWorkspace($f['emergency'], 2),
        'connection' => $l->disableConnection($f['emergency'], $f['metadata']['connection_id'], 1, 2),
    })->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($before);
})->with(['revoke', 'workspace', 'connection']);

test('fresh authority loss at audit time prevents lifecycle commit', function () {
    $f = tenantAiLifecycleFixture();
    $before = tenantAiLifecycleSnapshot();
    Activity::created(function ($event) use ($f) {
        if ($event->event === 'assistant.ai.credential_configured') {
            WorkspaceMembership::where('id', $f['context']->membershipId)->update(['is_active' => false]);
        }
    });
    expect(fn () => app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], 'synthetic-next'))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($before);
});

test('destroyed envelopes cannot be restored by direct SQL and metadata cannot be deleted', function () {
    $f = tenantAiLifecycleFixture();
    $before = (array) DB::table('tenant_ai_credentials')->first();
    app(TenantAiLifecycle::class)->revoke($f['emergency'], $f['metadata']['connection_id'], $f['metadata']['id'], 1, 1);
    foreach ([
        fn () => DB::table('tenant_ai_credentials')->where('id', $before['id'])->update(['secret_ciphertext' => $before['secret_ciphertext'], 'wrapped_dek' => $before['wrapped_dek'], 'kek_version' => $before['kek_version'], 'secret_destroyed_at' => null]),
        fn () => DB::table('tenant_ai_credentials')->where('id', $before['id'])->delete(),
        fn () => DB::table('tenant_ai_credentials')->where('id', $before['id'])->update(['state' => 'active']),
    ] as $attack) {
        expect(fn () => DB::transaction($attack))->toThrow(PDOException::class);
    }
    expect(DB::table('tenant_ai_credentials')->value('state'))->toBe('revoked')->and(DB::table('tenant_ai_credentials')->value('wrapped_dek'))->toBeNull();
});

test('connection revision overflow denies before candidate mutation', function () {
    $f = tenantAiLifecycleFixture();
    $connection = (array) DB::table('tenant_ai_connections')->first();
    $connection['id'] = (string) Str::uuid();
    $connection['revision'] = PHP_INT_MAX;
    DB::table('tenant_ai_connections')->insert($connection);
    $before = tenantAiLifecycleSnapshot();
    expect(fn () => app(TenantAiLifecycle::class)->candidate($f['context'], $connection['id'], 1, PHP_INT_MAX, null, 'synthetic-next'))
        ->toThrow(TenantAiStorageUnavailable::class)->and(tenantAiLifecycleSnapshot())->toBe($before);
});

test('lifecycle exception trace cannot disclose a captured secret when exception arguments are enabled', function () {
    $f = tenantAiLifecycleFixture();
    $marker = 'trace-lifecycle-sentinel-'.bin2hex(random_bytes(16));
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    config(['tenant_ai.kek_root' => null]);
    try {
        try {
            app(TenantAiLifecycle::class)->candidate($f['context'], $f['metadata']['connection_id'], 1, 1, $f['metadata']['id'], $marker);
            $this->fail('Expected fixed failure');
        } catch (TenantAiStorageUnavailable $error) {
            $cloner = new VarCloner;
            $dumper = new CliDumper;
            $dump = $dumper->dump($cloner->cloneVar([$error, $error->getTrace()]), true);
            expect($error->getPrevious())->toBeNull()->and($dump)->not->toContain($marker);
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('customer-managed pending selection can be disabled without verification approval reauthentication or keys', function () {
    $f = tenantAiLifecycleFixture();
    $profile = (array) DB::table('ai_model_profiles')->where('id', TenantAiCatalogue::ID)->first();
    $profile['id'] = (string) Str::uuid();
    $profile['profile_key'] = 'synthetic-customer-disable';
    $profile['manifest_sha256'] = hash('sha256', 'synthetic-customer-disable');
    $profile['allows_customer'] = true;
    DB::table('ai_model_profiles')->insert($profile);
    DB::table('tenant_ai_settings')->update(['mode' => 'customer_managed', 'profile_id' => $profile['id'],
        'connection_id' => $f['metadata']['connection_id'], 'credential_version_id' => null, 'revision' => 2]);
    $before = DB::table('tenant_ai_credentials')->get()->toJson();
    $f['request']->session()->forget('auth.password_confirmed_at');
    config(['tenant_ai.kek_root' => null]);
    expect(app(TenantAiLifecycle::class)->disableWorkspace($f['emergency'], 2))->toBe(['mode' => 'disabled']);
    $settings = DB::table('tenant_ai_settings')->first();
    expect($settings->connection_id)->toBeNull()->and($settings->profile_id)->toBeNull()->and($settings->credential_version_id)->toBeNull()
        ->and((int) $settings->revision)->toBe(3)->and(DB::table('tenant_ai_credentials')->get()->toJson())->toBe($before)
        ->and(DB::table('tenant_ai_credentials')->value('verification_state'))->toBe('unverified');
});
