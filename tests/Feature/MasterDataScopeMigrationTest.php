<?php

use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function scopeMigrationFixture(array $scopes): array
{
    $integration = Integration::factory()->create(['environment' => 'production']);
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id]);
    foreach ($scopes as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    FiscalDocument::factory()->create(['workspace_id' => $integration->workspace_id, 'legal_entity_id' => $integration->legal_entity_id]);

    return compact('integration', 'credential');
}

function masterScopeMigration(): Migration
{
    return require database_path('migrations/2026_10_07_142703_extend_integration_read_scopes.php');
}

test('scope migration preserves populated document grants identity and old fiscal evidence', function () {
    $f = scopeMigrationFixture(['documents:read']);
    $snapshot = [];
    foreach (['users', 'workspace_memberships', 'fiscal_documents', 'integrations', 'integration_credentials', 'integration_scopes', 'integration_credential_scopes', 'activity_log'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy($table === 'integration_scopes' ? 'integration_id' : ($table === 'integration_credential_scopes' ? 'credential_id' : 'id'))->get()->toJson();
    }
    $migration = masterScopeMigration();
    $migration->down();
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $f['integration']->id, 'scope' => 'customers:read'])))->toThrow(QueryException::class);
    $migration->up();
    foreach ($snapshot as $table => $bytes) {
        expect(DB::table($table)->orderBy($table === 'integration_scopes' ? 'integration_id' : ($table === 'integration_credential_scopes' ? 'credential_id' : 'id'))->get()->toJson())->toBe($bytes);
    }
    expect(fn () => DB::transaction(fn () => DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->update(['scope' => 'customers:read'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integrations')->where('id', $f['integration']->id)->update(['environment' => 'homologation'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integration_credentials')->where('id', $f['credential']->id)->update(['creator_attribution_id' => (string) Str::uuid()])))->toThrow(QueryException::class);
    if (DB::getDriverName() === 'sqlite') {
        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    }
});

test('closed master scope checks reject aliases duplicates and foreign relationships', function (string $scope) {
    $f = scopeMigrationFixture(['documents:read']);
    foreach (['integration_scopes' => ['integration_id', $f['integration']->id], 'integration_credential_scopes' => ['credential_id', $f['credential']->id]] as $table => [$key, $id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => $id, 'scope' => $scope])))->toThrow(QueryException::class);
    }
})->with(['*', 'admin', 'write', 'read', 'customers:*', 'Customers:read', ' customers:read', 'documents:read', 'customers:write', 'catalogue:write']);

test('scope rollback refuses both grants and retained capability audit even after grants removed', function (string $evidence) {
    $f = scopeMigrationFixture($evidence === 'grant' ? ['customers:read'] : ['documents:read']);
    if ($evidence === 'audit') {
        activity('capability')->event('catalogue.list')->log('Retained master read evidence');
    }
    expect(fn () => masterScopeMigration()->down())->toThrow(RuntimeException::class);
    DB::table('integration_scopes')->insert(['integration_id' => $f['integration']->id, 'scope' => 'catalogue:read']);
})->with(['grant', 'audit']);

test('failed narrowed replacement rolls back consistently and grants remain available', function () {
    $f = scopeMigrationFixture(['customers:read']);
    expect(fn () => DB::transaction(function (): void {
        DB::table('integration_scopes')->delete();
        masterScopeMigration()->down();
    }))->toThrow(RuntimeException::class);
    expect(DB::table('integration_scopes')->pluck('scope')->all())->toBe(['customers:read'])
        ->and(DB::table('integration_credential_scopes')->pluck('scope')->all())->toBe(['customers:read']);
});
