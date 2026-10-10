<?php

use App\Actions\DeleteUserAccount;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CustomerCommandFixtures.php';
beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true]);
});
function customerCommandMigration(): Migration
{
    return require database_path('migrations/2026_10_08_080150_add_external_customer_command_foundation.php');
}

test('empty command downgrade and reapply preserve all old grants and forbid new scope until upgraded', function () {
    $f = commandFixture(['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read', 'analytics:billing:read']);
    $before = DB::table('integration_credential_scopes')->orderBy('scope')->get()->toJson();
    customerCommandMigration()->down();
    expect(Schema::hasTable('external_command_operations'))->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $f['context']->integrationId, 'scope' => 'customers:create'])))->toThrow(QueryException::class);
    customerCommandMigration()->up();
    expect(DB::table('integration_credential_scopes')->orderBy('scope')->get()->toJson())->toBe($before);
    expect(fn () => DB::transaction(fn () => DB::table('integration_credential_scopes')->update(['scope' => 'admin'])))->toThrow(QueryException::class);
});

test('command downgrade refuses every kind of retained command evidence', function (string $kind) {
    $f = commandFixture($kind === 'grant' ? ['customers:create'] : ['documents:read']);
    if ($kind === 'audit') {
        activity('capability')->event('external.command.denied')->log('Retained evidence');
    }
    if ($kind === 'capacity') {
        DB::table('external_command_capacity')->insert(['integration_id' => $f['context']->integrationId, 'completed_count' => 0]);
    }
    expect(fn () => customerCommandMigration()->down())->toThrow(RuntimeException::class);
    expect(Schema::hasTable('external_command_operations'))->toBeTrue();
})->with(['grant', 'audit', 'capacity']);

test('actual creator deletion preserves frozen attribution and immutable result independently of customer deletion', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"A","tax_identification_number":"5401234567"}');
    $first = app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'delete');
    $owner = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['context']->workspaceId, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
    $before = DB::table('external_command_operations')->first();
    app(DeleteUserAccount::class)->execute($f['user']);
    $after = DB::table('external_command_operations')->first();
    expect($after->origin_sponsor_user_id)->toBeNull()->and($after->origin_sponsor_attribution_id)->toBe($before->origin_sponsor_attribution_id)
        ->and($after->response_body)->toBe($first['body'])->and($after->origin_credential_id)->toBe($before->origin_credential_id);
    expect(fn () => app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'delete'))->toThrow(HttpException::class);
});

function commandSchemaReservation(array $f): array
{
    return ['operation_id' => (string) Str::uuid(), 'integration_id' => $f['context']->integrationId,
        'origin_credential_id' => $f['context']->credentialId, 'workspace_id' => $f['context']->workspaceId, 'legal_entity_id' => $f['entity']->id,
        'environment' => 'production', 'command' => 'customers.create', 'capability_version' => 1, 'canonicalizer_version' => 'command-json-v1',
        'key_hash' => str_repeat('a', 64), 'fingerprint_hash' => str_repeat('b', 64), 'origin_sponsor_user_id' => $f['user']->id,
        'origin_sponsor_attribution_id' => $f['user']->attribution_id, 'state' => 'executing', 'created_at' => now()];
}

test('raw command reservations reject forged context state hashes and attribution', function (array $changes) {
    $f = commandFixture();
    expect(fn () => DB::transaction(fn () => DB::table('external_command_operations')->insert([...commandSchemaReservation($f), ...$changes])))->toThrow(QueryException::class);
    expect(DB::table('external_command_operations')->count())->toBe(0);
})->with([
    [['environment' => 'homologation']], [['command' => 'documents.issue']], [['capability_version' => 2]],
    [['canonicalizer_version' => 'unknown']], [['key_hash' => 'bad']], [['fingerprint_hash' => str_repeat('Z', 64)]],
    [['state' => 'succeeded']], [['origin_sponsor_user_id' => null]], [['workspace_id' => 999999]], [['legal_entity_id' => 999999]],
    [['origin_sponsor_attribution_id' => '77777777-7777-4777-8777-777777777777']],
]);

test('origin credential composite relationship cannot name a different integration', function () {
    $f = commandFixture();
    $other = commandFixture();
    expect(fn () => DB::transaction(fn () => DB::table('external_command_operations')->insert([...commandSchemaReservation($f), 'origin_credential_id' => $other['context']->credentialId])))->toThrow(QueryException::class);
    expect(DB::table('external_command_operations')->count())->toBe(0);
});

test('database uniqueness is the final namespace boundary even for concurrent reservation code', function () {
    $f = commandFixture();
    expect(fn () => DB::transaction(function () use ($f): void {
        DB::table('external_command_operations')->insert(commandSchemaReservation($f));
        DB::table('external_command_operations')->insert(commandSchemaReservation($f));
    }))->toThrow(QueryException::class);
    expect(DB::table('external_command_operations')->count())->toBe(0);
});

test('completion schema excludes any additional customer or result fields', function () {
    $f = commandFixture();
    expect(fn () => DB::transaction(function () use ($f): void {
        DB::table('external_command_capacity')->insert(['integration_id' => $f['context']->integrationId, 'completed_count' => 0]);
        $row = commandSchemaReservation($f);
        DB::table('external_command_operations')->insert($row);
        DB::table('external_command_operations')->update(['state' => 'succeeded', 'result_public_id' => '01arz3ndektsv4rrffq69g5fav', 'http_status' => 201,
            'response_body' => json_encode(['data' => ['public_id' => '01arz3ndektsv4rrffq69g5fav', 'name' => 'Excluded'], 'meta' => ['operation_id' => $row['operation_id']]], JSON_THROW_ON_ERROR), 'completed_at' => now()]);
    }))->toThrow(QueryException::class);
    expect(DB::table('external_command_operations')->count())->toBe(0)->and(DB::table('external_command_capacity')->count())->toBe(0);
});
