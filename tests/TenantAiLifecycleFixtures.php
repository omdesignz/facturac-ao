<?php

use App\Fiscal\TenantAiEmergencyContext;
use App\Fiscal\TenantAiStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/TenantAiFixtures.php';

function tenantAiLifecycleSetup(object $test): void
{
    Http::preventStrayRequests();
    tenantAiRoot();
    $test->lifecycleRoot = storage_path('framework/testing/lifecycle-'.Str::uuid());
    mkdir($test->lifecycleRoot, 0700, true);
    $test->lifecycleKey = $test->lifecycleRoot.'/key';
    file_put_contents($test->lifecycleKey, random_bytes(32));
    chmod($test->lifecycleKey, 0600);
    config(['tenant_ai.kek_root' => realpath($test->lifecycleRoot), 'tenant_ai.kek_version' => 'lifecycle-test',
        'tenant_ai.kek_files' => ['lifecycle-test' => $test->lifecycleKey]]);
}

function tenantAiLifecycleCleanup(object $test): void
{
    if (isset($test->lifecycleKey)) {
        @unlink($test->lifecycleKey);
        @rmdir($test->lifecycleRoot);
    }
    Http::assertNothingSent();
}

/** @return array<string, mixed> */
function tenantAiLifecycleFixture(): array
{
    $fixture = tenantAiFixture();
    $fixture['emergency'] = TenantAiEmergencyContext::resolve($fixture['request'], $fixture['context']->workspacePublicId);
    $fixture['secret'] = 'synthetic-lifecycle-'.bin2hex(random_bytes(24));
    $fixture['metadata'] = app(TenantAiStorage::class)->configure($fixture['context'], $fixture['secret']);

    return $fixture;
}

/** @return array<string, mixed> */
function tenantAiLifecycleSnapshot(): array
{
    return collect(['tenant_ai_settings', 'tenant_ai_connections', 'tenant_ai_credentials', 'activity_log'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'tenant_ai_settings' ? 'workspace_id' : 'id')->get()->toJson()])->all();
}
