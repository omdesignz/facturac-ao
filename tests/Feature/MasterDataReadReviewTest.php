<?php

use App\AgtEnvironment;
use App\Fiscal\CatalogueCapabilities;
use App\Fiscal\CatalogueListCommand;
use App\Fiscal\CatalogueReadCommand;
use App\Fiscal\CustomerCapabilities;
use App\Fiscal\CustomerListCommand;
use App\Fiscal\CustomerReadCommand;
use App\Fiscal\ExecutionContext;
use App\Fiscal\IntegrationReadContext;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function reviewedMasterFixture(string $environment = 'production'): array
{
    config(['integrations.enabled' => true]);
    $integration = Integration::factory()->create(['environment' => $environment]);
    $selector = (string) Str::ulid();
    $bearer = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $bearer)]);
    foreach (['customers:read', 'catalogue:read'] as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $user = User::findOrFail($integration->sponsor_user_id);
    $ownership = ['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id];
    $customer = Customer::factory()->create($ownership);
    $item = CatalogueItem::factory()->create($ownership);
    $path = 'workspaces/'.$entity->workspace->public_id.'/legal-entities/'.$entity->public_id.'/environments/'.$environment;

    return compact('integration', 'credential', 'bearer', 'entity', 'user', 'customer', 'item', 'path');
}

test('session master denials suppress ambient user agent and have independent operation identity', function (string $kind, string $method, int $status) {
    $f = reviewedMasterFixture();
    $url = '/api/v1/'.$f['path'].'/'.$kind.($status === 404 ? '/'.strtolower((string) Str::ulid()) : '?include=PRIVATE-SEARCH');
    $console = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $prior = $console->getValue($this->app);
    $console->setValue($this->app, false);
    try {
        $response = $this->actingAs($f['user'])->withHeader('User-Agent', 'PRIVATE-CONTACT '.$f['bearer'])->json($method, $url)->assertStatus($status);
    } finally {
        $console->setValue($this->app, $prior);
    }
    $event = $kind === 'customers' ? 'customers.read.denied' : 'catalogue.read.denied';
    $audit = Activity::where('event', $event)->latest('id')->firstOrFail();
    expect($audit->properties->get('user_agent'))->toBeNull()
        ->and($audit->properties->get('operation_id'))->toBeUuid()
        ->and($audit->properties->get('request_id'))->toBe($response->headers->get('X-Request-ID'))
        ->and($audit->properties->get('operation_id'))->not->toBe($audit->properties->get('request_id'))
        ->and($audit->properties->toJson())->not->toContain('PRIVATE-CONTACT')->not->toContain('PRIVATE-SEARCH')->not->toContain($f['bearer']);
    if ($method === 'HEAD') {
        $response->assertContent('');
    }
})->with(['customers', 'catalogue-items'])->with(['GET', 'HEAD'])->with([404, 422]);

test('master OpenAPI patterns accept runtime decimal values and correctly describe bounded responses', function (string $file) {
    $spec = json_decode(file_get_contents(base_path('docs/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    foreach (['CatalogueItemList', 'CatalogueItemDetail'] as $schema) {
        $pattern = $spec['components']['schemas'][$schema]['properties']['tax_percentage']['pattern'];
        expect(preg_match('~'.$pattern.'~', '14.00'))->toBe(1)->and(preg_match('~'.$pattern.'~', '14\\x00'))->toBe(0);
    }
    foreach (['CustomerListResponse', 'CatalogueListResponse'] as $schema) {
        expect($spec['components']['schemas'][$schema]['properties']['data']['maxItems'] ?? null)->toBe(50);
    }
    foreach ($spec['paths'] as $path => $operations) {
        if (! str_contains($path, '/customers') && ! str_contains($path, '/catalogue-items')) {
            continue;
        }
        foreach ($operations as $operation) {
            foreach ($operation['parameters'] as $parameter) {
                if ($parameter['name'] === 'q') {
                    expect($parameter['schema']['pattern'])->toBe('^[^\u0000-\u001F\u007F-\u009F]+$');
                }
            }
            foreach ($operation['responses'] as $response) {
                expect($response['headers']['Cache-Control']['schema']['const'] ?? null)->toBe('no-store, private');
            }
        }
    }
})->with(['openapi-read-v1.json', 'openapi-external-read-v1.json']);

test('direct human and machine homologation capabilities query no master data even for known identifiers', function (bool $machine, string $kind, bool $detail) {
    $f = reviewedMasterFixture('homologation');
    $context = $machine ? IntegrationReadContext::authenticate($f['bearer'], (string) Str::uuid())
        : ExecutionContext::resolve($f['user'], $f['entity'], AgtEnvironment::Homologation, readOnly: true);
    $queries = [];
    $observe = true;
    DB::connection()->beforeExecuting(function ($sql) use (&$queries, &$observe): void {
        if ($observe && preg_match('/from ["`]?(customers|catalogue_items)["`]?/i', $sql)) {
            $queries[] = $sql;
        }
    });
    try {
        foreach ([$f[$kind === 'customers' ? 'customer' : 'item']->public_id, strtolower((string) Str::ulid())] as $id) {
            try {
                if ($kind === 'customers') {
                    $detail ? app(CustomerCapabilities::class)->readCustomer($context, CustomerReadCommand::fromPublicId($id))
                        : app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput([]));
                } else {
                    $detail ? app(CatalogueCapabilities::class)->readItem($context, CatalogueReadCommand::fromPublicId($id))
                        : app(CatalogueCapabilities::class)->listItems($context, CatalogueListCommand::fromInput([]));
                }
                $this->fail('Homologation must be refused.');
            } catch (HttpException $exception) {
                expect($exception->getStatusCode())->toBe(403);
            }
        }
    } finally {
        $observe = false;
    }
    expect($queries)->toBe([])->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with([true, false])->with(['customers', 'catalogue'])->with([true, false]);

test('multi workspace sponsor cannot widen machine binding or grouped search', function (string $kind) {
    $f = reviewedMasterFixture();
    $other = reviewedMasterFixture();
    WorkspaceMembership::factory()->create(['workspace_id' => $other['entity']->workspace_id, 'user_id' => $f['user']->id, 'role' => 'owner', 'is_active' => true]);
    $target = $other[$kind === 'customers' ? 'customer' : 'item'];
    $target->update($kind === 'customers' ? ['name' => 'FOREIGN-ONLY-MATCH'] : ['code' => 'FOREIGN-ONLY-MATCH']);
    $suffix = $kind === 'customers' ? 'customers' : 'catalogue-items';
    $this->withHeader('Authorization', 'Bearer '.$f['bearer']);
    $this->getJson('/api/integrations/v1/'.$f['path'].'/'.$suffix.'?q=FOREIGN-ONLY-MATCH')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/integrations/v1/'.$other['path'].'/'.$suffix.'/'.$target->public_id)->assertForbidden();
    $this->getJson('/api/integrations/v1/'.$f['path'].'/'.$suffix.'/'.$target->public_id)->assertNotFound();
})->with(['customers', 'catalogue']);

test('external master denial envelopes and HEAD never distinguish foreign from absent targets', function (string $kind) {
    $f = reviewedMasterFixture();
    $other = reviewedMasterFixture();
    config(['app.debug' => true]);
    $suffix = $kind === 'customers' ? 'customers' : 'catalogue-items';
    $foreignId = $other[$kind === 'customers' ? 'customer' : 'item']->public_id;
    $this->withHeader('Authorization', 'Bearer '.$f['bearer']);
    $responses = [];
    foreach ([$foreignId, strtolower((string) Str::ulid())] as $id) {
        $url = '/api/integrations/v1/'.$f['path'].'/'.$suffix.'/'.$id;
        $get = $this->getJson($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $head = $this->json('HEAD', $url)->assertNotFound()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
        $responses[] = $get->json('error');
        expect($get->headers->get('X-Request-ID'))->toBeUuid()->not->toBe($head->headers->get('X-Request-ID'));
        $get->assertDontSee($id)->assertDontSee('trace')->assertDontSee('exception');
    }
    expect($responses[0])->toBe($responses[1]);
    $forbidden = [];
    foreach ([$other['entity']->public_id, strtolower((string) Str::ulid())] as $id) {
        $url = '/api/integrations/v1/'.str_replace($f['entity']->public_id, $id, $f['path']).'/'.$suffix;
        $forbidden[] = $this->getJson($url)->assertForbidden()->json('error');
        $this->json('HEAD', $url)->assertForbidden()->assertContent('');
    }
    $forbidden[] = $this->getJson('/api/integrations/v1/'.str_replace('production', 'homologation', $f['path']).'/'.$suffix)->assertForbidden()->json('error');
    DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete();
    $forbidden[] = $this->getJson('/api/integrations/v1/'.$f['path'].'/'.$suffix)->assertForbidden()->json('error');
    expect(array_unique(array_map('json_encode', $forbidden)))->toHaveCount(1);
    $audit = Activity::where('log_name', 'capability')->get()->toJson();
    expect($audit)->not->toContain($foreignId)->not->toContain($other['entity']->public_id)
        ->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['customers', 'catalogue']);

test('master denial audit failure never returns normal denial or data', function (bool $external, string $failure) {
    $f = reviewedMasterFixture();
    match ($failure) {
        'disabled' => activity()->disableLogging(),
        'buffered' => config(['activitylog.buffer.enabled' => true]),
        'cancelled' => Activity::creating(fn () => false),
        'throws' => Activity::creating(fn () => throw new RuntimeException('PRIVATE-AUDIT')),
    };
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['bearer']);
    $url = ($external ? '/api/integrations/v1/' : '/api/v1/').$f['path'].'/customers?include=PRIVATE-FILTER';
    $this->getJson($url)->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR')->assertDontSee('PRIVATE-');
    $this->json('HEAD', $url)->assertStatus(500)->assertContent('');
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
})->with([true, false])->with(['disabled', 'buffered', 'cancelled', 'throws']);
