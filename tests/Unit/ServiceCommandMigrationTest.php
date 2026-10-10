<?php

use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true]);
});

require_once __DIR__.'/../ServiceCommandFixtures.php';

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;

function serviceCommandMigration(): Migration
{
    return require database_path('migrations/2026_10_08_111140_extend_external_service_command_contract.php');
}

test('service upgrade and safe down preserve populated customer bytes grants and capacity', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"Customer","tax_identification_number":"5401234567"}');
    $result = app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'old');
    $tables = ['external_command_operations', 'external_command_capacity', 'integration_scopes', 'integration_credential_scopes'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }
    serviceCommandMigration()->down();
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    expect(app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'old')['body'])->toBe($result['body']);
    expect(fn () => DB::transaction(fn () => DB::table('integration_scopes')->insert(['integration_id' => $f['context']->integrationId, 'scope' => 'catalogue:services:create'])))->toThrow(QueryException::class);
    serviceCommandMigration()->up();
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    expect(app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'old')['body'])->toBe($result['body']);
});

test('service down refuses retained service grants or minimized history', function (string $evidence) {
    $f = serviceFixture($evidence === 'grant' ? ['catalogue:services:create'] : ['customers:create']);
    if ($evidence === 'audit') {
        activity('capability')->event('external.command.denied')->withProperties(['capability' => 'catalogue.services.create'])->log('Denied');
    }
    if ($evidence === 'lifecycle') {
        activity('integration')->event('integration.grant-reduced')->withProperties(['previous_scopes' => ['catalogue:services:create']])->log('Retained grant');
    }
    if ($evidence === 'human') {
        activity('catalogue-item')->event('catalogue.service.created')->log('Created');
    }
    expect(fn () => serviceCommandMigration()->down())->toThrow(RuntimeException::class);
})->with(['grant', 'audit', 'human', 'lifecycle']);
