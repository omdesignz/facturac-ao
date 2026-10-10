<?php

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

require_once __DIR__.'/../ServiceCommandFixtures.php';

use App\AgtEnvironment;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\ServiceCreateInput;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\IntegrationCredential;

test('service boundary rejects all product and ownership aliases', function (string $field) {
    $f = serviceFixture();
    $response = $this->postJson($f['url'], servicePayload([$field => 'poison']), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x']);
    $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with(['type', 'tracks_stock', 'track_stock', 'stock_quantity', 'quantity', 'warehouse_id', 'reorder_level', 'reorder_level_units', 'stock_scale', 'cost', 'purchase_cost', 'supplier_id', 'lot', 'batch', 'accounting_code', 'unit_id', 'tax_id', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code', 'workspace_id', 'legal_entity_id', 'environment', 'public_id', 'id', 'is_active', 'created_at', 'price_list_id', 'unit_price', 'audit']);

test('service boundary rejects unsafe monetary and tax values', function (array $change) {
    $f = serviceFixture();
    $this->postJson($f['url'], servicePayload($change), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertUnprocessable();
    expect(CatalogueItem::count())->toBe(0);
})->with(array_map(fn (array $value): array => [$value], [['unit_price_minor' => 0], ['unit_price_minor' => 1.1], ['unit_price_minor' => '-1'], ['unit_price_minor' => '-0'], ['unit_price_minor' => '+1'], ['unit_price_minor' => '01'], ['unit_price_minor' => '1e2'], ['unit_price_minor' => '1.0'], ['unit_price_minor' => ' 1'], ['unit_price_minor' => '9223372036854775808'], ['unit_price_minor' => str_repeat('9', 50)], ['currency_code' => 'USD'], ['currency_code' => 'aoa'], ['tax_treatment' => 'UNKNOWN'], ['tax_treatment' => 'iva_nor_14'], ['unit_of_measure' => ''], ['unit_of_measure' => '123'], ['description' => null], ['description' => ''], ['description' => str_repeat('x', 256)], ['name' => "bad\0name"]]));

test('service boundary enforces independent scope and production before business queries', function (string $scope) {
    $f = serviceFixture([$scope]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->postJson($f['url'], servicePayload(), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertForbidden();
    expect(implode(' ', $queries))->not->toContain('from "catalogue_items"')->and(CatalogueItem::count())->toBe(0);
})->with(['customers:create', 'catalogue:read', 'customers:read', 'documents:read', 'documents:agt-status:read', 'analytics:billing:read']);

test('service boundary exact response is minimal and replay bytes do not serialize model fields', function () {
    $f = serviceFixture();
    $h = ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'];
    $a = $this->postJson($f['url'], servicePayload(), $h)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
    $b = $this->postJson($f['url'], servicePayload(), $h)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($a->getContent())->toBe($b->getContent())->and(array_keys($a->json()))->toBe(['data', 'meta'])->and(array_keys($a->json('data')))->toBe(['public_id']);
    $audit = Activity::where('event', 'catalogue.service.created')->firstOrFail();
    expect($audit->properties->get('capability'))->toBe('catalogue.services.create')->and($audit->properties->get('environment'))->toBe('production');
    expect(json_encode($audit->properties))->not->toContain('CONSULT-01', '10000', 'IVA_NOR_14', $f['secret']);
});

test('service unsupported methods authenticate and deny without effect', function (string $verb) {
    $f = serviceFixture();
    $r = $this->call($verb, $f['url'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret']]);
    $r->assertStatus(405)->assertHeader('Allow', 'POST');
    if ($verb === 'HEAD') {
        expect($r->getContent())->toBe('');
    }
    expect(CatalogueItem::count())->toBe(0);
})->with(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

test('service duplicate decoded JSON members and unknown query reject', function () {
    expect(fn () => ServiceCreateInput::external('{"code":"A","co\\u0064e":"B","name":"N","unit_price_minor":"0","currency_code":"AOA","tax_treatment":"NS_M02"}'))->toThrow(HttpException::class);
    $f = serviceFixture();
    $this->postJson($f['url'].'?unused=', servicePayload(), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertUnprocessable();
});

test('customer and service requests share the integration write budget', function () {
    $f = serviceFixture(['customers:create', 'catalogue:services:create']);
    for ($i = 0; $i < 10; $i++) {
        $url = $i % 2 === 0 ? $f['url'] : substr($f['url'], 0, -strlen('catalogue-services')).'customers';
        $this->postJson($url, ['unknown' => 'x'], ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertUnprocessable();
    }
    $this->postJson($f['url'], servicePayload(), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertStatus(429);
    expect(DB::table('external_command_operations')->count())->toBe(0);
});

test('service denial audits retain verified environment in both path mismatch directions', function (bool $homologationCredential) {
    $f = serviceFixture();
    if ($homologationCredential) {
        $issued = app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Homologation', ['documents:read']);
        $secret = $issued->revealOnce();
        $credential = IntegrationCredential::where('public_id', $issued->credentialPublicId)->firstOrFail();
        DB::table('integration_scopes')->insert(['integration_id' => $credential->integration_id, 'scope' => 'catalogue:services:create']);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'catalogue:services:create']);
    } else {
        $secret = $f['secret'];
        $f['url'] = str_replace('/production/', '/homologation/', $f['url']);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->postJson($f['url'], servicePayload(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'deny'])->assertForbidden();
    expect(implode(' ', $queries))->not->toContain('from "catalogue_items"', 'from "external_command_operations"');
    expect(Activity::where('event', 'external.command.denied')->sole()->properties->get('environment'))->toBe($homologationCredential ? 'homologation' : 'production');
})->with([false, true]);

test('service credential grant fails homologation before one-time secret issuance', function () {
    $f = serviceFixture();
    $before = IntegrationCredential::count();
    expect(fn () => app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Forbidden', ['catalogue:services:create']))->toThrow(HttpException::class);
    expect(IntegrationCredential::count())->toBe($before);
});

test('service transport rejects invalid body envelope without creating ledger state', function (string $body, int $status) {
    $f = serviceFixture();
    $this->call('POST', $f['url'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret'], 'HTTP_IDEMPOTENCY_KEY' => 'x', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus($status);
    expect(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with([['[]', 400], ['{', 400], ['null', 400], [str_repeat('x', 4097), 413], ['{"code":"A","name":"N","unit_price_minor":"0","currency_code":"AOA","tax_treatment":"NS_M02","nested":{"a":{"b":{"c":1}}}}', 400]]);

test('service-only scope cannot authorize existing customer creation or any read surface', function () {
    $f = serviceFixture();
    $h = ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'];
    $customerUrl = substr($f['url'], 0, -strlen('catalogue-services')).'customers';
    $this->postJson($customerUrl, ['name' => 'N', 'tax_identification_number' => '5401234567'], $h)->assertForbidden();
    foreach (['customers', 'catalogue-items', 'documents'] as $resource) {
        $url = 'https://localhost/api/integrations/v1/workspaces/'.$f['context']->workspacePublicId.'/legal-entities/'.$f['context']->entityPublicId.'/environments/production/'.$resource;
        $response = $this->getJson($url, $h);
        $response->assertForbidden();
    }
    expect(Customer::count())->toBe(0)->and(CatalogueItem::count())->toBe(0);
});

test('service path cannot substitute foreign or nonexistent tenant bindings', function (bool $workspace, bool $exists) {
    $f = serviceFixture();
    $other = serviceFixture();
    $original = $workspace ? $f['context']->workspacePublicId : $f['context']->entityPublicId;
    $replacement = $exists ? ($workspace ? $other['context']->workspacePublicId : $other['context']->entityPublicId) : '01arz3ndektsv4rrffq69g5fav';
    $this->postJson(str_replace($original, $replacement, $f['url']), servicePayload(), ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => 'x'])->assertForbidden();
    expect(CatalogueItem::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with([false, true])->with([false, true]);
