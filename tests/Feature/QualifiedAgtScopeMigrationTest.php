<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function qualifiedScopeMigration(): Migration
{
    return require database_path('migrations/2026_10_07_201201_extend_qualified_agt_read_scope.php');
}

/** @return array{integration: Integration, credential: IntegrationCredential} */
function qualifiedScopeFixture(): array
{
    $integration = Integration::factory()->create(['environment' => 'production']);
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id]);
    foreach (['documents:read', 'customers:read', 'catalogue:read'] as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }

    return compact('integration', 'credential');
}

test('qualified scope migration preserves all populated rows and integrity without granting authority', function () {
    $f = qualifiedScopeFixture();
    $before = [];
    foreach (['integrations', 'integration_credentials', 'integration_scopes', 'integration_credential_scopes'] as $table) {
        $before[$table] = DB::table($table)->orderBy($table === 'integration_scopes' ? 'integration_id' : ($table === 'integration_credential_scopes' ? 'credential_id' : 'id'))->get()->toJson();
    }
    $migration = qualifiedScopeMigration();
    $migration->down();
    foreach (['integration_scopes' => ['integration_id', $f['integration']->id], 'integration_credential_scopes' => ['credential_id', $f['credential']->id]] as $table => [$key,$id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => $id, 'scope' => 'documents:agt-status:read'])))->toThrow(QueryException::class);
    }
    $migration->up();
    foreach ($before as $table => $bytes) {
        expect(DB::table($table)->orderBy($table === 'integration_scopes' ? 'integration_id' : ($table === 'integration_credential_scopes' ? 'credential_id' : 'id'))->get()->toJson())->toBe($bytes);
    }
    foreach (['integration_scopes' => ['integration_id', $f['integration']->id], 'integration_credential_scopes' => ['credential_id', $f['credential']->id]] as $table => [$key,$id]) {
        DB::table($table)->insert([$key => $id, 'scope' => 'documents:agt-status:read']);
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => $id, 'scope' => 'documents:agt-status:read'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => 999999, 'scope' => 'documents:agt-status:read'])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->where('scope', 'documents:read')->update(['scope' => 'documents:agt-status:read'])))->toThrow(QueryException::class);
    if (DB::getDriverName() === 'sqlite') {
        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    }
});

test('qualified scope migration raw SQL exact allowlist rejects every alias', function (string $scope) {
    $f = qualifiedScopeFixture();
    foreach (['integration_scopes' => ['integration_id', $f['integration']->id], 'integration_credential_scopes' => ['credential_id', $f['credential']->id]] as $table => [$key,$id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => $id, 'scope' => $scope])))->toThrow(QueryException::class);
    }
})->with(['*', 'admin', 'write', 'documents:write', 'documents:*', 'Documents:agt-status:read', ' documents:agt-status:read', 'documents:agt-status:read ', 'documents:agt-status:read-all']);

test('qualified scope downgrade refuses grants and immutable V2 audit history even when revoked', function (string $evidence) {
    $f = qualifiedScopeFixture();
    $f['credential']->update(['revoked_at' => now()]);
    if ($evidence === 'parent') {
        DB::table('integration_scopes')->insert(['integration_id' => $f['integration']->id, 'scope' => 'documents:agt-status:read']);
    } elseif ($evidence === 'credential') {
        DB::table('integration_credential_scopes')->insert(['credential_id' => $f['credential']->id, 'scope' => 'documents:agt-status:read']);
    } else {
        activity('capability')->event($evidence)->withProperties(['capability_version' => 2])->log('Retained V2 evidence');
    }
    expect(fn () => qualifiedScopeMigration()->down())->toThrow(RuntimeException::class);
})->with(['parent', 'credential', 'documents.agt-status.read', 'documents.agt-status.read.denied', 'integration.read.denied']);

test('qualified scope interrupted table replacement rolls back and resumes atomically', function () {
    $f = qualifiedScopeFixture();
    qualifiedScopeMigration()->down();
    $interrupted = false;
    DB::listen(function (QueryExecuted $event) use (&$interrupted): void {
        if (! $interrupted && str_contains(strtolower($event->sql), 'rename') && str_contains($event->sql, 'integration_scopes')) {
            $interrupted = true;
            throw new RuntimeException('Injected interruption between scope tables');
        }
    });
    expect(fn () => qualifiedScopeMigration()->up())->toThrow(RuntimeException::class);
    foreach (['integration_scopes' => ['integration_id', $f['integration']->id], 'integration_credential_scopes' => ['credential_id', $f['credential']->id]] as $table => [$key,$id]) {
        expect(DB::table($table)->pluck('scope')->sort()->values()->all())->toBe(['catalogue:read', 'customers:read', 'documents:read']);
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([$key => $id, 'scope' => 'documents:agt-status:read'])))->toThrow(QueryException::class);
    }
    qualifiedScopeMigration()->up();
    DB::table('integration_scopes')->insert(['integration_id' => $f['integration']->id, 'scope' => 'documents:agt-status:read']);
});
