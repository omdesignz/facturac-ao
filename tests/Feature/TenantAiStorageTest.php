<?php

use App\Actions\DeleteUserAccount;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\AssistantPlan;
use App\Fiscal\TenantAiCapabilities;
use App\Fiscal\TenantAiEnvelope;
use App\Fiscal\TenantAiKeyFile;
use App\Fiscal\TenantAiStorage;
use App\Models\TenantAiCredential;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../TenantAiFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    tenantAiRoot();
    $directory = storage_path('framework/testing');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $this->keyPath = $directory.'/tai-'.Str::uuid();
    file_put_contents($this->keyPath, random_bytes(32));
    chmod($this->keyPath, 0600);
    config(['tenant_ai.kek_root' => realpath($directory), 'tenant_ai.kek_version' => 'test-v1', 'tenant_ai.kek_files' => ['test-v1' => $this->keyPath]]);
    $this->secret = 'synthetic-'.bin2hex(random_bytes(24));
    $this->storage = app(TenantAiStorage::class);
});

afterEach(function () {
    @unlink($this->keyPath);
    Activity::flushEventListeners();
});

test('tenant storage encrypts raw rows audits and serializations without changing activation', function () {
    $f = tenantAiFixture();
    $seen = [];
    DB::listen(function ($query) use (&$seen) {
        $seen[] = json_encode($query->bindings);
    });
    Log::spy();
    $metadata = $this->storage->configure($f['context'], $this->secret);
    $raw = (array) DB::table('tenant_ai_credentials')->first();
    expect($raw['secret_ciphertext'])->not->toBe($this->secret)->and($raw['wrapped_dek'])->not->toBe($this->secret);
    foreach (['tenant_ai_settings', 'tenant_ai_connections', 'tenant_ai_credentials', 'activity_log'] as $table) {
        expect(DB::table($table)->get()->toJson())->not->toContain($this->secret);
    }
    expect(implode('', $seen))->not->toContain($this->secret)->not->toContain($raw['secret_ciphertext'])->not->toContain($raw['wrapped_dek']);
    $model = TenantAiCredential::firstOrFail();
    foreach ([json_encode($metadata), $model->toJson(), json_encode($model->toArray()), print_r($model, true), json_encode(new JsonResource($model))] as $bytes) {
        expect($bytes)->not->toContain($this->secret)->not->toContain($raw['secret_ciphertext'])->not->toContain($raw['wrapped_dek']);
    }
    expect(fn () => serialize($model))->toThrow(TenantAiStorageUnavailable::class);
    $matched = false;
    $this->storage->inspectForStorageTest($f['context'], $metadata['id'], function ($secret) use (&$matched) {
        $matched = hash_equals($this->secret, $secret);
    });
    expect($matched)->toBeTrue()->and(config('assistant.provider.enabled'))->toBeFalse()->and(config('assistant.provider.egress_enabled'))->toBeFalse();
    $settings = (array) DB::table('tenant_ai_settings')->first();
    expect($settings['mode'])->toBe('disabled')->and($settings['profile_id'])->toBeNull()->and($settings['credential_version_id'])->toBeNull();
    foreach (TenantAiCapabilities::COLUMNS as $column) {
        expect((bool) $settings[$column])->toBeFalse();
    }
    expect(DB::table('assistant_provider_attempts')->count())->toBe(0)->and(DB::table('assistant_provider_windows')->count())->toBe(0);
    expect(Activity::where('event', 'assistant.ai.credential_configured')->count())->toBe(1);
    Log::shouldNotHaveReceived('error');
    Http::assertNothingSent();
});

test('CM1 flags are bijective deny by default and unknown tools have no mapping', function () {
    expect(array_keys(TenantAiCapabilities::COLUMNS))->toBe(array_keys(AssistantPlan::PERMISSIONS))
        ->and(array_unique(array_values(TenantAiCapabilities::COLUMNS)))->toHaveCount(5)
        ->and(TenantAiCapabilities::column('futureCustomerTool'))->toBeNull();
    $columns = DB::getSchemaBuilder()->getColumnListing('tenant_ai_settings');
    expect($columns)->not->toContain('catalogue_enabled')->not->toContain('customers_enabled');
    $f = tenantAiFixture();
    $this->storage->configure($f['context'], $this->secret);
    foreach (TenantAiCapabilities::COLUMNS as $column) {
        DB::table('tenant_ai_settings')->where('workspace_id', $f['context']->workspaceId)->update([$column => true, 'revision' => DB::raw('revision+1')]);
        $row = (array) DB::table('tenant_ai_settings')->first();
        foreach (TenantAiCapabilities::COLUMNS as $other) {
            expect((bool) $row[$other])->toBe($column === $other);
        }
        expect($row['mode'])->toBe('disabled')->and(config('assistant.provider.enabled'))->toBeFalse();
        DB::table('tenant_ai_settings')->update([$column => false, 'revision' => DB::raw('revision+1')]);
    }
});

test('foreign metadata candidate substitution and decrypt deny with no mutation', function () {
    $a = tenantAiFixture();
    $aMeta = $this->storage->configure($a['context'], $this->secret);
    $b = tenantAiFixture();
    $bMeta = $this->storage->configure($b['context'], $this->secret);
    auth('web')->login($a['user']);
    foreach ([$bMeta['id'], (string) Str::uuid()] as $id) {
        expect(fn () => $this->storage->metadata($a['context'], $id))->toThrow(TenantAiStorageUnavailable::class);
        expect(fn () => $this->storage->inspectForStorageTest($a['context'], $id, fn () => throw new RuntimeException('must not run')))->toThrow(TenantAiStorageUnavailable::class);
    }
    expect(fn () => $this->storage->configure($a['context'], $this->secret, $bMeta['connection_id']))->toThrow(TenantAiStorageUnavailable::class);
    expect(fn () => TenantAiCredential::findOrFail($bMeta['id'])->delete())->toThrow(TenantAiStorageUnavailable::class);
    expect(fn () => TenantAiCredential::findOrFail($bMeta['id'])->forceFill(['workspace_id' => $a['context']->workspaceId])->save())->toThrow(TenantAiStorageUnavailable::class);
    expect(DB::table('tenant_ai_credentials')->count())->toBe(2);
    expect($this->storage->metadata($a['context'], $aMeta['id'])['id'])->toBe($aMeta['id']);
});

test('owner authority is freshly required for storage', function (string $attack) {
    $f = tenantAiFixture();
    match ($attack) {
        'admin' => WorkspaceMembership::where('id', $f['context']->membershipId)->update(['role' => 'administrator']),
        'removed' => WorkspaceMembership::where('id', $f['context']->membershipId)->delete(),
        'inactive' => WorkspaceMembership::where('id', $f['context']->membershipId)->update(['is_active' => false]),
        'mfa' => $f['user']->forceFill(['two_factor_confirmed_at' => null])->save(),
        'email' => $f['user']->forceFill(['email_verified_at' => null])->save(),
        'reauth' => $f['request']->session()->put('auth.password_confirmed_at', time() - 301),
        'session' => $f['request']->session()->put((string) config('work_session.started_at_key'), time() - 86400),
        'csrf' => $f['request']->headers->set('X-CSRF-TOKEN', 'wrong'),
        'impersonation' => $f['request']->session()->put((string) config('impersonation.session.impersonator'), 1),
        'deployment' => config(['tenant_ai.deployment_id' => (string) Str::uuid()]),
    };
    expect(fn () => $this->storage->configure($f['context'], $this->secret))->toThrow(TenantAiStorageUnavailable::class);
    expect(DB::table('tenant_ai_credentials')->count())->toBe(0);
})->with(['admin', 'removed', 'inactive', 'mfa', 'email', 'reauth', 'session', 'csrf', 'impersonation', 'deployment']);

test('envelope tampering and context substitution fail closed', function (string $attack) {
    $f = tenantAiFixture();
    $meta = $this->storage->configure($f['context'], $this->secret);
    $row = (array) DB::table('tenant_ai_credentials')->first();
    $connection = DB::table('tenant_ai_connections')->first();
    $binding = ['schema' => 1, 'deployment_id' => $f['context']->deploymentId, 'workspace_public_id' => $f['context']->workspacePublicId, 'connection_id' => $meta['connection_id'], 'version_id' => $meta['id'], 'provider' => $connection->provider_key, 'credential_family' => $connection->credential_family, 'endpoint_policy' => $connection->endpoint_policy_key];
    if (in_array($attack, ['value', 'iv', 'tag'], true)) {
        $payload = json_decode(base64_decode($row['secret_ciphertext']), true);
        $payload[$attack] = base64_encode(random_bytes($attack === 'iv' ? 12 : 16));
        $row['secret_ciphertext'] = base64_encode(json_encode($payload));
    } elseif ($attack === 'wrapped') {
        $row['wrapped_dek'] = $row['secret_ciphertext'];
    } elseif ($attack === 'version') {
        $row['kek_version'] = 'unknown';
    } elseif ($attack === 'schema') {
        $row['encryption_schema'] = 2;
    } else {
        $binding[$attack] = (string) Str::uuid();
    }
    $revealed = false;
    expect(fn () => TenantAiEnvelope::inspectForStorageTest($f['context'], $row, $binding, app(TenantAiKeyFile::class), function () use (&$revealed) {
        $revealed = true;
    }))->toThrow(TenantAiStorageUnavailable::class);
    expect($revealed)->toBeFalse();
})->with(['value', 'iv', 'tag', 'wrapped', 'version', 'schema', 'workspace_public_id', 'version_id', 'connection_id', 'provider', 'credential_family', 'endpoint_policy', 'deployment_id']);

test('audit failure rolls back complete custody transaction and errors are bounded', function () {
    $f = tenantAiFixture();
    Activity::creating(fn () => throw new RuntimeException('simulated audit failure'));
    try {
        $this->storage->configure($f['context'], $this->secret);
        $this->fail('Expected rejection');
    } catch (TenantAiStorageUnavailable $e) {
        expect($e->getMessage())->toBe('AI credential storage unavailable.')->and($e->getPrevious())->toBeNull();
    }
    foreach (['tenant_ai_settings', 'tenant_ai_connections', 'tenant_ai_credentials'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

test('pending uniqueness failure does not leak or replace original envelope', function () {
    $f = tenantAiFixture();
    $meta = $this->storage->configure($f['context'], $this->secret);
    $before = DB::table('tenant_ai_credentials')->get()->toJson();
    expect(fn () => $this->storage->configure($f['context'], $this->secret, $meta['connection_id']))->toThrow(TenantAiStorageUnavailable::class);
    expect(DB::table('tenant_ai_credentials')->get()->toJson())->toBe($before);
});

test('invalid secrets and missing protected key never persist state', function (string $attack) {
    $f = tenantAiFixture();
    $secret = $this->secret;
    if ($attack === 'controls') {
        $secret .= "\n";
    }
    if ($attack === 'large') {
        $secret = str_repeat('x', 513);
    }
    if ($attack === 'empty') {
        $secret = '';
    }
    if ($attack === 'missing') {
        config(['tenant_ai.kek_version' => 'absent']);
    }
    if ($attack === 'permissions') {
        chmod($this->keyPath, 0644);
    }
    expect(fn () => $this->storage->configure($f['context'], $secret))->toThrow(TenantAiStorageUnavailable::class);
    expect(DB::table('tenant_ai_credentials')->count())->toBe(0)->and(DB::table('tenant_ai_settings')->count())->toBe(0);
})->with(['controls', 'large', 'empty', 'missing', 'permissions']);

test('known VAP key cannot enter customer storage and APP_KEY is not the envelope key', function () {
    $f = tenantAiFixture();
    file_put_contents($this->keyPath, $this->secret);
    config(['assistant.provider.secret_reference' => $this->keyPath]);
    expect(fn () => $this->storage->configure($f['context'], $this->secret))->toThrow(TenantAiStorageUnavailable::class);
    config(['assistant.provider.secret_reference' => null]);
    file_put_contents($this->keyPath, random_bytes(32));
    $meta = $this->storage->configure($f['context'], $this->secret);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $matched = false;
    $this->storage->inspectForStorageTest($f['context'], $meta['id'], function ($s) use (&$matched) {
        $matched = hash_equals($this->secret, $s);
    });
    expect($matched)->toBeTrue();
});

test('retained AI custody blocks workspace deletion and destructive migration rollback', function () {
    $f = tenantAiFixture();
    $this->storage->configure($f['context'], $this->secret);
    expect(app(DeleteUserAccount::class)->preview($f['user'])['can_delete'])->toBeFalse();
    expect(fn () => app(DeleteUserAccount::class)->execute($f['user']))->toThrow(BillingActionRefused::class);
    expect(fn () => (require database_path('migrations/2026_10_09_010739_create_tenant_ai_storage_tables.php'))->down())->toThrow(RuntimeException::class);
});

test('whole ciphertext pair transplantation across stored tenants fails cryptographically', function () {
    $a = tenantAiFixture();
    $ma = $this->storage->configure($a['context'], $this->secret);
    $ra = (array) DB::table('tenant_ai_credentials')->where('id', $ma['id'])->first();
    $b = tenantAiFixture();
    $mb = $this->storage->configure($b['context'], $this->secret);
    $rb = (array) DB::table('tenant_ai_credentials')->where('id', $mb['id'])->first();
    expect($ra['secret_ciphertext'])->not->toBe($rb['secret_ciphertext'])->and($ra['wrapped_dek'])->not->toBe($rb['wrapped_dek']);
    $c = DB::table('tenant_ai_connections')->where('id', $mb['connection_id'])->first();
    $binding = ['schema' => 1, 'deployment_id' => $b['context']->deploymentId, 'workspace_public_id' => $b['context']->workspacePublicId, 'connection_id' => $mb['connection_id'], 'version_id' => $mb['id'], 'provider' => $c->provider_key, 'credential_family' => $c->credential_family, 'endpoint_policy' => $c->endpoint_policy_key];
    $rb['secret_ciphertext'] = $ra['secret_ciphertext'];
    $rb['wrapped_dek'] = $ra['wrapped_dek'];
    $revealed = false;
    expect(fn () => TenantAiEnvelope::inspectForStorageTest($b['context'], $rb, $binding, app(TenantAiKeyFile::class), function () use (&$revealed) {
        $revealed = true;
    }))->toThrow(TenantAiStorageUnavailable::class);
    expect($revealed)->toBeFalse();
});

test('known key alias tampering and production test harness calls deny', function () {
    $f = tenantAiFixture();
    $meta = $this->storage->configure($f['context'], $this->secret);
    app()->instance('env', 'production');
    $revealed = false;
    try {
        expect(fn () => $this->storage->inspectForStorageTest($f['context'], $meta['id'], function () use (&$revealed) {
            $revealed = true;
        }))->toThrow(TenantAiStorageUnavailable::class);
        expect($revealed)->toBeFalse();
    } finally {
        app()->instance('env', 'testing');
    }
    $row = (array) DB::table('tenant_ai_credentials')->first();
    $c = DB::table('tenant_ai_connections')->first();
    $binding = ['schema' => 1, 'deployment_id' => $f['context']->deploymentId, 'workspace_public_id' => $f['context']->workspacePublicId, 'connection_id' => $meta['connection_id'], 'version_id' => $meta['id'], 'provider' => $c->provider_key, 'credential_family' => $c->credential_family, 'endpoint_policy' => $c->endpoint_policy_key];
    config(['tenant_ai.kek_files.test-alias' => $this->keyPath]);
    $row['kek_version'] = 'test-alias';
    expect(fn () => TenantAiEnvelope::inspectForStorageTest($f['context'], $row, $binding, app(TenantAiKeyFile::class), function () use (&$revealed) {
        $revealed = true;
    }))->toThrow(TenantAiStorageUnavailable::class);
    expect($revealed)->toBeFalse();
});

test('direct SQL cannot erase retained credentials or bypass identity flags and envelope guards', function (string $attack) {
    $f = tenantAiFixture();
    $meta = $this->storage->configure($f['context'], $this->secret);
    $operation = match ($attack) {
        'delete' => fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->delete(),
        'creator' => fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['creator_attribution_id' => (string) Str::uuid()]),
        'null_flag' => fn () => DB::table('tenant_ai_settings')->update(['customer_detail_enabled' => null, 'revision' => 2]),
        'unknown_mode' => fn () => DB::table('tenant_ai_settings')->update(['mode' => 'automatic', 'revision' => 2]),
        'ciphertext' => fn () => DB::table('tenant_ai_credentials')->where('id', $meta['id'])->update(['secret_ciphertext' => 'corrupted']),
        'payer' => fn () => DB::table('tenant_ai_connections')->update(['payer_budget_id' => (string) Str::uuid(), 'revision' => 2]),
    };
    expect(fn () => DB::transaction($operation))->toThrow(PDOException::class);
    expect(DB::table('tenant_ai_credentials')->count())->toBe(1);
})->with(['delete', 'creator', 'null_flag', 'unknown_mode', 'ciphertext', 'payer']);

test('storage errors never report sensitive values on validation encryption database or audit failures', function (string $failure) {
    $f = tenantAiFixture();
    $records = [];
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    if ($failure === 'key') {
        config(['tenant_ai.kek_version' => 'missing']);
    }
    if ($failure === 'audit') {
        Activity::creating(fn () => throw new RuntimeException('synthetic audit failure'));
    }
    if ($failure === 'database') {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION tai_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic database failure'; END; \$\$");
            DB::statement('CREATE TRIGGER tai_test_fail BEFORE INSERT ON tenant_ai_credentials FOR EACH ROW EXECUTE FUNCTION tai_test_fail()');
        } else {
            DB::statement("CREATE TRIGGER tai_test_fail BEFORE INSERT ON tenant_ai_credentials BEGIN SELECT RAISE(ABORT,'synthetic database failure'); END");
        }
    }
    try {
        $this->storage->configure($f['context'], $failure === 'validation' ? $this->secret."\n" : $this->secret);
        $this->fail('Expected storage refusal');
    } catch (TenantAiStorageUnavailable $error) {
        report($error);
        expect($error->getPrevious())->toBeNull();
        expect((string) $error)->not->toContain($this->secret);
    } finally {
        Log::getLogger()->popHandler();
    }
    expect(json_encode($handler->getRecords()))->not->toContain($this->secret);
    expect(DB::table('tenant_ai_credentials')->count())->toBe(0)->and(DB::table('activity_log')->where('event', 'assistant.ai.credential_configured')->count())->toBe(0);
})->with(['validation', 'key', 'database', 'audit']);

test('storage has no gateway consumption binding and cannot add a runtime permission', function () {
    foreach (['VapAiGateway', 'LegacyAssistantAiGateway', 'AnthropicIntentPlanner', 'AssistantProviderTransport', 'AssistantIntentGateway', 'GatewayAssistantPlanner'] as $name) {
        expect(file_get_contents(app_path('Fiscal/'.$name.'.php')))->not->toContain('TenantAi');
    }
    expect(file_get_contents(app_path('Providers/AppServiceProvider.php')))->not->toContain('TenantAi');
    $permissions = AssistantPlan::PERMISSIONS;
    $f = tenantAiFixture();
    $this->storage->configure($f['context'], $this->secret);
    DB::table('tenant_ai_settings')->update(['billing_enabled' => true, 'revision' => 2]);
    WorkspaceMembership::where('id', $f['context']->membershipId)->update(['role' => 'viewer']);
    expect(fn () => $this->storage->metadata($f['context'], DB::table('tenant_ai_credentials')->value('id')))->toThrow(TenantAiStorageUnavailable::class);
    expect(AssistantPlan::PERMISSIONS)->toBe($permissions)->and(config('assistant.provider.enabled'))->toBeFalse()->and(config('assistant.provider.egress_enabled'))->toBeFalse();
});

test('empty storage migration roundtrip preserves existing tenant and legacy metadata', function () {
    $f = tenantAiFixture();
    $before = DB::table('workspaces')->get()->toJson();
    $legacy = DB::table('assistant_provider_controls')->get()->toJson();
    $schema = require database_path('migrations/2026_10_09_010739_create_tenant_ai_storage_tables.php');
    $seed = require database_path('migrations/2026_10_09_011029_seed_tenant_ai_storage_catalogue.php');
    $successors = DB::getDriverName() === 'pgsql'
        ? array_map(fn ($path) => require $path, glob(database_path('migrations/*_ai_verification_*.php'))) : [];
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
    expect(DB::table('workspaces')->get()->toJson())->toBe($before)->and(DB::table('assistant_provider_controls')->get()->toJson())->toBe($legacy);
    expect(DB::table('tenant_ai_settings')->count())->toBe(0)->and(DB::table('tenant_ai_credentials')->count())->toBe(0);
});
