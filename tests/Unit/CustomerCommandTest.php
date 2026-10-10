<?php

use App\Fiscal\CommandIdempotency;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true]);
});

require_once __DIR__.'/../CustomerCommandFixtures.php';

test('customer command commits one effect and replays an immutable acknowledgement', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"Café","tax_identification_number":"5401234567"}');
    $first = app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'one');
    $second = app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'one');
    expect($first['body'])->toBe($second['body'])->and($first['replayed'])->toBeFalse()->and($second['replayed'])->toBeTrue()
        ->and(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1)
        ->and(Activity::where('event', 'external.command.replayed')->count())->toBe(1);
});

test('external command route creates and replays the exact body', function () {
    $f = commandFixture();
    $headers = ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'http'];
    $input = ['name' => 'Padaria', 'tax_identification_number' => '5401234567'];
    $first = $this->postJson($f['url'], $input, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->postJson($f['url'], $input, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($first->getContent())->toBe($second->getContent())->and(Customer::count())->toBe(1);
});

test('pure normalization preserves semantic equivalence without collapsing different names', function () {
    $f = commandFixture();
    $command = app(ExternalCustomerCommand::class);
    $a = CustomerCreateInput::external('{"name":"Cafe\u0301","tax_identification_number":" ab1234567 "}');
    $b = CustomerCreateInput::external('{"country_code":" ao ","tax_identification_number":"AB1234567","name":"Café"}');
    expect($command->canonical($f['context'], $a))->toBe($command->canonical($f['context'], $b));
    $c = CustomerCreateInput::external('{"name":"CAFÉ","tax_identification_number":"AB1234567"}');
    expect($command->canonical($f['context'], $a))->not->toBe($command->canonical($f['context'], $c));
});

test('strict customer input rejects forbidden or malformed representations', function (string $json, int $status) {
    try {
        CustomerCreateInput::external($json);
        $this->fail('Input accepted');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
})->with([
    ['{"name":"A","name":"B","tax_identification_number":"5401234567"}', 400],
    ['{"na\u006de":"A","name":"B","tax_identification_number":"5401234567"}', 400],
    ['[]', 400], ['null', 400], ['{', 400],
    ['{"name":"A","tax_identification_number":5401234567}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","country_code":null}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","email":null}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","is_active":false}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","workspace_id":1}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","auto_send_documents":true}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","country_code":""}', 422],
    ['{"name":"A\\nB","tax_identification_number":"5401234567"}', 422],
    ['{"name":"  ","tax_identification_number":"5401234567"}', 422],
    ['{"name":"A","tax_identification_number":"54-1234567"}', 422],
    ['{"name":"A","tax_identification_number":"5401234567","nested":{"a":1,"a":2}}', 400],
]);

test('HTTP conflicts distinguish business duplicates from idempotency', function () {
    $f = commandFixture();
    $h = ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'conflict'];
    $payload = ['name' => 'A', 'tax_identification_number' => '5401234567'];
    $this->postJson($f['url'], $payload, $h)->assertCreated();
    $this->postJson($f['url'], [...$payload, 'name' => 'B'], $h)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    $h['Idempotency-Key'] = 'different';
    $this->postJson($f['url'], $payload, $h)->assertStatus(409)->assertJsonPath('error.code', 'CUSTOMER_CONFLICT');
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1);
});

test('all old scopes deny commands and create scope denies customer reads', function (string $scope) {
    $f = commandFixture([$scope]);
    $this->postJson($f['url'], ['name' => 'A', 'tax_identification_number' => '5401234567'], ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'scope'])->assertForbidden();
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with(['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read', 'analytics:billing:read']);

test('success replay is historical and does not requery customer or default records', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"A","tax_identification_number":"5401234567"}');
    $service = app(ExternalCustomerCommand::class);
    $first = $service->execute($f['context'], $input, 'history');
    Customer::query()->sole()->update(['name' => 'Renamed', 'is_active' => false]);
    $second = app(CommandIdempotency::class)->execute(
        $f['context'], 'history', hash('sha256', $service->canonical($f['context'], $input)),
        fn () => throw new LogicException('Replay must never invoke the business effect.'),
    );
    expect($second['body'])->toBe($first['body'])->and($second['replayed'])->toBeTrue()
        ->and(Customer::query()->sole()->name)->toBe('Renamed')
        ->and(Activity::where('event', 'customer.created')->count())->toBe(1);
});

test('withdrawn current authority blocks an already completed replay', function (string $withdrawal) {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"A","tax_identification_number":"5401234567"}');
    $service = app(ExternalCustomerCommand::class);
    $service->execute($f['context'], $input, 'withdraw');
    match ($withdrawal) {
        'credential' => DB::table('integration_credentials')->where('id', $f['context']->credentialId)->update(['revoked_at' => now()]),
        'expired' => DB::table('integration_credentials')->where('id', $f['context']->credentialId)->update(['expires_at' => CarbonImmutable::parse(DB::table('integration_credentials')->where('id', $f['context']->credentialId)->value('created_at'))->addSecond()]),
        'parent' => DB::table('integrations')->where('id', $f['context']->integrationId)->update(['revoked_at' => now()]),
        'scope' => DB::table('integration_scopes')->where('integration_id', $f['context']->integrationId)->delete(),
        'role' => DB::table('workspace_memberships')->where('id', $f['context']->membershipId)->update(['role' => 'viewer']),
        'membership' => DB::table('workspace_memberships')->where('id', $f['context']->membershipId)->update(['is_active' => false]),
        'mfa' => DB::table('users')->where('id', $f['user']->id)->update(['two_factor_confirmed_at' => null]),
        'email' => DB::table('users')->where('id', $f['user']->id)->update(['email_verified_at' => null]),
    };
    if ($withdrawal === 'expired') {
        usleep(1_100_000);
    }
    expect(fn () => $service->execute($f['context'], $input, 'withdraw'))->toThrow(HttpException::class);
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1);
})->with(['credential', 'expired', 'parent', 'scope', 'role', 'membership', 'mfa', 'email']);

test('required audit failure rolls back customer and command state', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"A","tax_identification_number":"5401234567"}');
    Activity::creating(function (Activity $activity) {
        if ($activity->event === 'customer.created') {
            return false;
        }
    });
    expect(fn () => app(ExternalCustomerCommand::class)->execute($f['context'], $input, 'audit'))->toThrow(RuntimeException::class);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0)
        ->and(DB::table('external_command_capacity')->count())->toBe(0)
        ->and(Activity::where('event', 'external.command.authorized')->count())->toBe(0);
});

test('unsupported command methods preserve authority and are never read aliases', function (string $method) {
    $f = commandFixture();
    $r = $this->call($method, $f['url'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret']]);
    $r->assertStatus(405)->assertHeader('Allow', 'POST');
    if ($method === 'HEAD') {
        expect($r->getContent())->toBe('');
    }
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

test('command flags are independent and disabled before authentication', function () {
    $f = commandFixture();
    config(['integrations.commands_enabled' => false]);
    $this->postJson($f['url'], [])->assertNotFound();
    expect(Customer::count())->toBe(0);
});
