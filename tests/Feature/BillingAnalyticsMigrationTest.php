<?php

use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function billingScopeMigration(): Migration
{
    return require database_path('migrations/2026_10_07_220541_extend_billing_analytics_read_scope.php');
}

function billingIndexMigration(): Migration
{
    return require database_path('migrations/2026_10_07_220542_add_billing_analytics_month_index.php');
}

test('billing scope populated roundtrip preserves prior grants constraints and immutability', function () {
    $integration = Integration::factory()->create(['environment' => 'production']);
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id]);
    foreach (['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read'] as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    $before = DB::table('integration_credential_scopes')->orderBy('scope')->get()->toJson();
    billingScopeMigration()->down();
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'analytics:billing:read'])))->toThrow(QueryException::class);
    billingScopeMigration()->up();
    expect(DB::table('integration_credential_scopes')->orderBy('scope')->get()->toJson())->toBe($before);
    DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'analytics:billing:read']);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'analytics:billing:read']);
    expect(fn () => DB::transaction(fn () => DB::table('integration_credential_scopes')->where('credential_id', $credential->id)->where('scope', 'analytics:billing:read')->update(['scope' => 'documents:*'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integration_credential_scopes')->insert(['credential_id' => 999999, 'scope' => 'analytics:billing:read'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'analytics:billing:read'])))->toThrow(QueryException::class);
});

test('billing exact scope rejects all generic malformed and widened aliases', function (string $scope) {
    $i = Integration::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $i->id, 'scope' => $scope])))->toThrow(QueryException::class);
})->with(['*', 'admin', 'reports:read', 'analytics:read', 'analytics:*', 'analytics:billing:write', 'Analytics:billing:read', ' analytics:billing:read', 'analytics:billing:read ']);

test('billing scope downgrade preserves named retained evidence and does not erase it', function (string $event) {
    activity('capability')->event($event)->log('Retained metric evidence');
    expect(fn () => billingScopeMigration()->down())->toThrow(RuntimeException::class);
    expect(DB::table('activity_log')->where('event', $event)->count())->toBe(1);
})->with(['analytics.billing.read', 'analytics.billing.read.denied']);

test('billing generic older V2 audit is not a new analytics grant and index roundtrip preserves fiscal bytes', function () {
    activity('capability')->event('integration.read.denied')->withProperties(['capability_version' => 2])->log('Generic denial');
    $document = FiscalDocument::factory()->issued()->create();
    $before = $document->fresh()->getRawOriginal();
    billingScopeMigration()->down();
    billingScopeMigration()->up();
    $index = billingIndexMigration();
    $index->down();
    $index->up();
    expect($document->fresh()->getRawOriginal())->toBe($before);
    $indexes = Schema::getIndexes('fiscal_documents');
    $found = array_values(array_filter($indexes, fn ($i) => $i['name'] === 'fiscal_documents_analytics_month_idx'));
    expect($found)->toHaveCount(1)->and($found[0]['columns'])->toBe(['workspace_id', 'legal_entity_id', 'environment', 'document_date', 'id'])->and($found[0]['unique'])->toBeFalse();
});

test('billing scope interruption rolls back both table checks and safely resumes', function () {
    billingScopeMigration()->down();
    $fired = false;
    DB::listen(function ($event) use (&$fired) {
        if (! $fired && str_contains($event->sql, 'RENAME') && str_contains($event->sql, 'integration_scopes')) {
            $fired = true;
            throw new RuntimeException('Interrupted billing migration');
        }
    });
    expect(fn () => billingScopeMigration()->up())->toThrow(RuntimeException::class);
    billingScopeMigration()->up();
    $i = Integration::factory()->create();
    DB::table('integration_scopes')->insert(['integration_id' => $i->id, 'scope' => 'analytics:billing:read']);
    expect(DB::table('integration_scopes')->count())->toBe(1);
});
