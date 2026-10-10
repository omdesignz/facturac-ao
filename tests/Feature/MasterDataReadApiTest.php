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
use App\Fiscal\SensitiveReadQuery;
use App\Http\Resources\CatalogueItemReadResource;
use App\Http\Resources\CustomerReadResource;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array<string, mixed> */
function masterReadFixture(array $scopes = ['documents:read', 'customers:read', 'catalogue:read'], string $environment = 'production'): array
{
    $integration = Integration::factory()->create(['environment' => $environment]);
    $selector = (string) Str::ulid();
    $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $secret)]);
    foreach ($scopes as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $user = User::findOrFail($integration->sponsor_user_id);
    $ownership = ['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id];
    $customer = Customer::factory()->create([...$ownership, 'name' => 'Cliente Árvore', 'tax_identification_number' => 'NIF-HIDDEN',
        'email' => 'contact-hidden@example.test', 'phone' => 'PHONE-HIDDEN', 'address_line' => 'ADDRESS-HIDDEN',
        'credit_limit_minor' => 87654321, 'payment_terms_days' => 53, 'auto_send_documents' => true]);
    $item = CatalogueItem::factory()->create([...$ownership, 'code' => 'ART-001', 'name' => 'Árvore serviço', 'description' => 'DESCRIPTION-HIDDEN',
        'unit_price_minor' => 123456, 'tracks_stock' => true, 'reorder_level_units' => 1234567]);
    $document = FiscalDocument::factory()->create([...$ownership, 'environment' => $environment]);

    return compact('integration', 'credential', 'secret', 'entity', 'user', 'customer', 'item', 'document', 'environment');
}

function masterReadUrl(array $f, string $kind = 'customers', bool $detail = false, bool $external = true): string
{
    return route(($external ? 'integrations.v1.' : 'api.v1.').$kind.($detail ? '.show' : '.index'), [
        'workspacePublicId' => $f['entity']->workspace->public_id, 'entityPublicId' => $f['entity']->public_id, 'environment' => $f['environment'],
        ...($detail ? [$kind === 'customers' ? 'customerPublicId' : ($kind === 'catalogue' ? 'itemPublicId' : 'documentPublicId') => $f[$kind === 'customers' ? 'customer' : ($kind === 'catalogue' ? 'item' : 'document')]->public_id] : []),
    ]);
}

function masterReadContext(array $f): IntegrationReadContext
{
    return IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
}

beforeEach(function () {
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

test('master read scopes remain independently grantable for every subset', function (int $mask) {
    $all = ['documents:read', 'customers:read', 'catalogue:read'];
    $scopes = array_values(array_filter($all, fn ($scope, $index) => ($mask & (1 << $index)) !== 0, ARRAY_FILTER_USE_BOTH));
    $f = masterReadFixture($scopes);
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    foreach (['documents', 'customers', 'catalogue'] as $index => $kind) {
        foreach ([false, true] as $detail) {
            $this->getJson(masterReadUrl($f, $kind, $detail))->assertStatus(($mask & (1 << $index)) !== 0 ? 200 : 403);
        }
    }
})->with(range(0, 7));

test('master projections use exact allowlists and stable machine attribution', function (bool $external) {
    $f = masterReadFixture();
    if ($external) {
        $this->actingAs(User::factory()->withWorkspace()->create())->withHeader('Authorization', 'Bearer '.$f['secret']);
        Context::add(['workspace_id' => 999, 'impersonator_id' => 777]);
    } else {
        $this->actingAs($f['user']);
    }
    $response = $this->getJson(masterReadUrl($f, detail: true, external: $external))->assertOk();
    expect(array_keys($response->json('data')))->toBe(['public_id', 'name', 'country_code', 'is_active']);
    expect($response->json('meta.data_scope'))->toBe('legal_entity_master')->and($response->json('meta.environment'))->toBe('production');
    foreach (['NIF-HIDDEN', 'contact-hidden', 'PHONE-HIDDEN', 'ADDRESS-HIDDEN', '87654321', 'payment_terms', 'auto_send'] as $secret) {
        $response->assertDontSee($secret);
    }
    $list = $this->getJson(masterReadUrl($f, 'catalogue', external: $external))->assertOk();
    $detail = $this->getJson(masterReadUrl($f, 'catalogue', true, $external))->assertOk();
    expect($list->json('data.0'))->toHaveCount(13)->not->toHaveKeys(['description', 'tracks_stock', 'stock_scale', 'reorder_level_units', 'id']);
    expect($detail->json('data'))->toHaveCount(14)->and($detail->json('data.unit_price_minor'))->toBe('123456')
        ->and($detail->json('data.price_scale'))->toBe(2)->and($detail->json('data.description'))->toBe('DESCRIPTION-HIDDEN');
    $audit = Activity::where('event', 'customers.read')->latest('id')->firstOrFail();
    expect($audit->properties['workspace_id'])->toBe($f['entity']->workspace_id)->and($audit->properties['data_scope'])->toBe('legal_entity_master');
    if ($external) {
        expect($audit->causer_type)->toBe(Integration::class)->and($audit->properties['human_actor_id'])->toBeNull()
            ->and($audit->properties['authority_user_id'])->toBe($f['user']->id)->and($f['credential']->fresh()->last_used_at)->not->toBeNull();
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with([true, false]);

test('homologation master reads deny before all master queries and do not reveal existence', function (bool $external, string $kind, bool $detail) {
    $f = masterReadFixture(environment: 'homologation');
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        $seen[] = $query->sql;
    });
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $url = masterReadUrl($f, $kind, $detail, $external);
    $this->getJson($url)->assertForbidden();
    $this->json('HEAD', $url)->assertForbidden()->assertContent('');
    if ($detail) {
        $id = $f[$kind === 'customers' ? 'customer' : 'item']->public_id;
        $this->getJson(str_replace($id, strtolower((string) Str::ulid()), $url))->assertForbidden();
    }
    expect(collect($seen)->filter(fn ($sql) => preg_match('/from ["`]?(customers|catalogue_items)["`]?/i', $sql)))->toHaveCount(0);
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
    expect(fn () => $kind === 'customers'
        ? app(CustomerCapabilities::class)->listCustomers(masterReadContext($f), CustomerListCommand::fromInput([]))
        : app(CatalogueCapabilities::class)->listItems(masterReadContext($f), CatalogueListCommand::fromInput([])))->toThrow(HttpException::class);
})->with([true, false])->with(['customers', 'catalogue'])->with([true, false]);

test('foreign absent sibling and switched browser contexts never broaden master reads', function (bool $external, string $kind) {
    $f = masterReadFixture();
    $other = masterReadFixture();
    $sibling = LegalEntity::factory()->create(['workspace_id' => $f['entity']->workspace_id]);
    $model = $kind === 'customers' ? Customer::class : CatalogueItem::class;
    $foreign = $model::factory()->create(['workspace_id' => $sibling->workspace_id, 'legal_entity_id' => $sibling->id]);
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $detail = masterReadUrl($f, $kind, true, $external);
    foreach ([$foreign->public_id, $other[$kind === 'customers' ? 'customer' : 'item']->public_id, strtolower((string) Str::ulid())] as $id) {
        $this->getJson(str_replace($f[$kind === 'customers' ? 'customer' : 'item']->public_id, $id, $detail))->assertNotFound();
    }
    $f['user']->forceFill(['current_workspace_id' => $other['entity']->workspace_id])->save();
    $this->getJson(masterReadUrl($f, $kind, external: $external))->assertOk()->assertJsonPath('meta.total', 1);
    foreach ([$other['entity']->workspace->public_id, strtolower((string) Str::ulid())] as $id) {
        $this->getJson(str_replace($f['entity']->workspace->public_id, $id, $detail))->assertForbidden();
    }
    foreach ([$other['entity']->public_id, strtolower((string) Str::ulid())] as $id) {
        $this->getJson(str_replace($f['entity']->public_id, $id, $detail))->assertForbidden();
    }
})->with([true, false])->with(['customers', 'catalogue']);

test('master capability authorization rechecks every authority boundary', function (string $change, string $kind) {
    $f = masterReadFixture();
    $context = masterReadContext($f);
    match ($change) {
        'parent-grant' => DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete(),
        'credential-grant' => DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->delete(),
        'role' => WorkspaceMembership::whereKey($f['integration']->sponsor_membership_id)->update(['role' => 'viewer']),
        'membership' => WorkspaceMembership::whereKey($f['integration']->sponsor_membership_id)->delete(),
        'mfa' => $f['user']->forceFill(['two_factor_secret' => null])->save(),
        'email' => $f['user']->forceFill(['email_verified_at' => null])->save(),
        'credential' => $f['credential']->update(['revoked_at' => now()]),
        'parent' => $f['integration']->update(['revoked_at' => now()]),
        'expiry' => $f['credential']->forceFill(['created_at' => now()->subDays(2), 'expires_at' => now()->subDay()])->save(),
    };
    expect(fn () => $kind === 'customers'
        ? app(CustomerCapabilities::class)->readCustomer($context, CustomerReadCommand::fromPublicId($f['customer']->public_id))
        : app(CatalogueCapabilities::class)->readItem($context, CatalogueReadCommand::fromPublicId($f['item']->public_id)))->toThrow(HttpException::class);
    expect(Activity::whereIn('event', ['customers.read', 'catalogue.read'])->count())->toBe(0);
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['parent-grant', 'credential-grant', 'role', 'membership', 'mfa', 'email', 'credential', 'parent', 'expiry'])->with(['customers', 'catalogue']);

test('all human view roles retain explicit master reads and revalidate after context creation', function (WorkspaceRole $role) {
    $f = masterReadFixture();
    WorkspaceMembership::whereKey($f['integration']->sponsor_membership_id)->update(['role' => $role->value]);
    $context = ExecutionContext::resolve($f['user'], $f['entity'], AgtEnvironment::Production, readOnly: true);
    expect(app(CustomerCapabilities::class)->readCustomer($context, CustomerReadCommand::fromPublicId($f['customer']->public_id))['public_id'])->toBe($f['customer']->public_id);
    $f['user']->forceFill(['two_factor_confirmed_at' => null])->save();
    expect(fn () => app(CatalogueCapabilities::class)->readItem($context, CatalogueReadCommand::fromPublicId($f['item']->public_id)))->toThrow(HttpException::class);
})->with(WorkspaceRole::cases());

test('master list filters and pagination have deterministic minimized semantics', function (bool $external) {
    $f = masterReadFixture();
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $ownership = ['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id];
    $inactive = Customer::factory()->create([...$ownership, 'name' => 'Cliente inactivo', 'is_active' => false]);
    Customer::factory()->create([...$ownership, 'name' => 'Outra pessoa']);
    $base = masterReadUrl($f, external: $external);
    $this->getJson($base)->assertJsonPath('meta.total', 2);
    $this->getJson($base.'?status=inactive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.public_id', $inactive->public_id);
    $this->getJson($base.'?status=all&per_page=1&page=2')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.public_id', $inactive->public_id);
    $this->getJson($base.'?page=999')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson(str_replace($f['customer']->public_id, $inactive->public_id, masterReadUrl($f, detail: true, external: $external)))->assertOk()->assertJsonPath('data.is_active', false);
    foreach (['NIF-HIDDEN', 'contact-hidden', 'PHONE-HIDDEN', 'ADDRESS-HIDDEN'] as $hidden) {
        $this->getJson($base.'?q='.rawurlencode($hidden))->assertJsonPath('meta.total', 0);
    }
    $catalogue = masterReadUrl($f, 'catalogue', external: $external);
    $this->getJson($catalogue.'?q=DESCRIPTION-HIDDEN')->assertJsonPath('meta.total', 0);
    $this->getJson($catalogue.'?code=ART-001')->assertJsonPath('meta.total', 1);
    $this->getJson($catalogue.'?code=art-001')->assertJsonPath('meta.total', 0);
    $this->getJson($catalogue.'?code=%20ART-001%20')->assertJsonPath('meta.total', 0);
    $this->getJson($catalogue.'?type='.$f['item']->type->value.'&q=%C3%81rvore')->assertJsonPath('meta.total', 1);
})->with([true, false]);

test('master search is literal case sensitive unicode and cannot escape tenant grouping', function (string $term) {
    $f = masterReadFixture();
    $f['customer']->update(['name' => 'Prefix '.$term.' Suffix']);
    $f['item']->update(['name' => 'Prefix '.$term.' Suffix']);
    $other = masterReadFixture();
    $other['item']->update(['code' => 'FOREIGN-CODE', 'name' => 'Prefix '.$term.' Suffix']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    foreach (['customers', 'catalogue'] as $kind) {
        $url = masterReadUrl($f, $kind).'?q='.rawurlencode(' '.$term.' ');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1);
    }
})->with(['%_', '\\path', "' OR 1=1 --", 'Árvore', '中文']);

test('invalid master filters and body input are sanitized and audited for both methods', function (string $query, bool $external) {
    $f = masterReadFixture();
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $url = masterReadUrl($f, external: $external).'?'.$query;
    $this->getJson($url)->assertUnprocessable()->assertDontSee('SQLSTATE');
    $this->json('HEAD', $url)->assertUnprocessable()->assertContent('');
    expect(Activity::where('event', 'customers.read.denied')->count())->toBe(2);
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['q=', 'q=a', 'q[]=name', 'q=%FF%FE', 'q=hi%00x', 'q=hi%09x', 'per_page=51', 'page=0', 'page=10001', 'status=hidden', 'type=product', 'tax_identification_number=NIF-HIDDEN', 'include=contacts', 'workspace_id=1', 'q='.str_repeat('a', 81)])->with([true, false]);

test('new master GET HEAD rejects bodies and keeps durable audit cache and correlation behavior', function (bool $external, string $method) {
    $f = masterReadFixture();
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $url = masterReadUrl($f, detail: true, external: $external);
    $this->json($method, $url, ['on_behalf_of' => 'SECRET-BODY'])->assertUnprocessable()->assertDontSee('SECRET-BODY');
    $first = $this->json($method, $url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $second = $this->json($method, $url)->assertOk();
    expect($first->headers->get('X-Request-ID'))->not->toBe($second->headers->get('X-Request-ID'));
    if ($method === 'HEAD') {
        $first->assertContent('');
    }
    expect(Activity::where('event', 'customers.read')->count())->toBe(2);
})->with([true, false])->with(['GET', 'HEAD']);

test('master reads fail closed when audit is disabled buffered cancelled or throws', function (string $failure, string $kind, bool $external) {
    $f = masterReadFixture();
    match ($failure) {
        'disabled' => activity()->disableLogging(),
        'buffered' => config(['activitylog.buffer.enabled' => true]),
        'cancelled' => Activity::creating(fn () => false),
        'throws' => Activity::creating(fn () => throw new RuntimeException('AUDIT-PRIVATE')),
    };
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $this->getJson(masterReadUrl($f, $kind, external: $external))->assertStatus(500)->assertDontSee('Cliente')->assertDontSee('AUDIT-PRIVATE');
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['disabled', 'buffered', 'cancelled', 'throws'])->with(['customers', 'catalogue'])->with([true, false]);

test('catalogue prices remain exact base values and legacy tax configuration is not repaired', function (string $price) {
    $f = masterReadFixture();
    $f['item']->update(['unit_price_minor' => $price, 'currency_code' => 'USD', 'tax_code' => null, 'tax_percentage' => '14.00']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(masterReadUrl($f, 'catalogue', true))->assertOk()
        ->assertJsonPath('data.unit_price_minor', $price)->assertJsonPath('data.currency_code', 'USD')
        ->assertJsonPath('data.tax_code', null)->assertJsonPath('data.tax_percentage', '14.00');
    $f['item']->refresh();
    expect($f['item']->tax_code)->toBeNull();
})->with(['0', '9007199254740993', '9223372036854775807']);

test('future extra fields cannot change resources and query events cannot leak searches', function () {
    $f = masterReadFixture();
    $context = masterReadContext($f);
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        $seen[] = [$query->sql, $query->bindings];
    });
    DB::enableQueryLog();
    $page = app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput(['q' => 'Cliente Árvore']));
    $data = $page->items()[0];
    $data['future_contact'] = 'CONTACT-PRIVATE';
    expect((new CustomerReadResource($data))->resolve())->not->toHaveKey('future_contact');
    $catalogue = app(CatalogueCapabilities::class)->readItem($context, CatalogueReadCommand::fromPublicId($f['item']->public_id));
    $catalogue['future_stock'] = 100;
    expect((new CatalogueItemReadResource($catalogue))->resolve())->not->toHaveKey('future_stock');
    expect(json_encode($seen))->not->toContain('Cliente Árvore')->and(json_encode(DB::getQueryLog()))->not->toContain('Cliente Árvore');
    expect(DB::connection()->logging())->toBeTrue();
    DB::disableQueryLog();
    $properties = Activity::where('event', 'customers.list')->latest('id')->firstOrFail()->properties;
    expect($properties['search_applied'])->toBeTrue()->and(json_encode($properties))->not->toContain('Cliente Árvore')->not->toContain('NIF-HIDDEN');
});

test('sensitive read failures remove bindings and restore logging and event state', function () {
    $connection = DB::connection();
    $events = $connection->getEventDispatcher();
    $connection->enableQueryLog();
    try {
        SensitiveReadQuery::run(fn () => DB::select('SELECT * FROM master_missing WHERE name = ?', ['PII-QUERY-PRIVATE']));
        $this->fail('Expected sanitized database failure');
    } catch (RuntimeException $exception) {
        expect($exception)->not->toBeInstanceOf(QueryException::class)->and($exception->getPrevious())->toBeNull();
        $handler = new TestHandler;
        $handler->setFormatter(new JsonFormatter);
        $logger = new Logger('test', [$handler]);
        $logger->error('Query failed', ['exception' => $exception]);
        expect($handler->getRecords()[0]->formatted)->not->toContain('PII-QUERY-PRIVATE')->not->toContain('master_missing');
    }
    expect($connection->logging())->toBeTrue()->and($connection->getEventDispatcher())->toBe($events);
    $connection->disableQueryLog();
});

test('new resources share all existing external quotas across alternating families', function () {
    $f = masterReadFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson(masterReadUrl($f, ['documents', 'customers', 'catalogue'][$i % 3]))->assertOk();
    }
    $this->json('HEAD', masterReadUrl($f))->assertTooManyRequests()->assertContent('')->assertHeader('Retry-After');
    expect(Activity::where('event', 'customers.read.denied')->count())->toBe(1);
});

test('master disabled anonymous errors and unsupported methods remain safe', function (bool $external) {
    $f = masterReadFixture();
    $url = masterReadUrl($f, external: $external);
    if ($external) {
        config(['integrations.enabled' => false]);
        $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($url)->assertNotFound();
        config(['integrations.enabled' => true]);
    }
    $this->flushHeaders()->getJson($url)->assertUnauthorized();
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $this->postJson($url, [])->assertStatus(405)->assertHeader('Allow');
    $this->json('HEAD', $url)->assertOk()->assertContent('');
})->with([true, false]);

test('both specifications exactly match new routes closed schemas and minimized responses', function (bool $external) {
    $spec = json_decode(file_get_contents(base_path('docs/'.($external ? 'openapi-external-read-v1.json' : 'openapi-read-v1.json'))), true, flags: JSON_THROW_ON_ERROR);
    $f = masterReadFixture();
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $prefix = $external ? 'api/integrations/v1/' : 'api/v1/';
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), $prefix));
    expect($routes)->toHaveCount(6)->and($spec['paths'])->toHaveCount(6);
    foreach ($routes as $route) {
        expect($route->methods())->toBe(['GET', 'HEAD'])->and($spec['paths'])->toHaveKey('/'.$route->uri());
    }
    foreach (['customers', 'catalogue'] as $kind) {
        foreach ([true, false] as $detail) {
            $response = $this->getJson(masterReadUrl($f, $kind, $detail, $external))->assertOk();
            $name = $kind === 'customers' ? 'CustomerRead' : ($detail ? 'CatalogueItemDetail' : 'CatalogueItemList');
            $schema = $spec['components']['schemas'][$name];
            expect($schema['additionalProperties'])->toBeFalse();
            $actual = array_keys($detail ? $response->json('data') : $response->json('data.0'));
            $expected = array_keys($schema['properties']);
            sort($actual);
            sort($expected);
            expect($actual)->toBe($expected);
            $meta = $spec['components']['schemas'][$detail ? 'MasterReadMeta' : 'MasterListMeta'];
            $actual = array_keys($response->json('meta'));
            $expected = array_keys($meta['properties']);
            sort($actual);
            sort($expected);
            expect($actual)->toBe($expected)->and($meta['additionalProperties'])->toBeFalse();
        }
    }
    foreach ($spec['paths'] as $path => $operations) {
        expect(array_keys($operations))->toBe(['get', 'head']);
        foreach ($operations['head']['responses'] as $response) {
            expect($response)->not->toHaveKey('content');
        }
        if (str_contains($path, '/customers') || str_contains($path, '/catalogue-items')) {
            expect($operations['get']['responses'])->toHaveKeys(['401', '403', '404', '405', '422', '429', '500', '503']);
            if ($external) {
                expect($operations['get']['x-required-scope'])->toBe(str_contains($path, '/customers') ? 'customers:read' : 'catalogue:read');
            }
        }
    }
})->with([true, false]);

test('scoped master query count stays constant and selected columns exclude hidden data', function (string $kind) {
    $f = masterReadFixture();
    $sql = [];
    $observe = true;
    DB::connection()->beforeExecuting(function ($query) use (&$sql, &$observe): void {
        if ($observe && preg_match('/from "(customers|catalogue_items)"/i', $query)) {
            $sql[] = $query;
        }
    });
    $context = masterReadContext($f);
    $read = fn () => $kind === 'customers' ? app(CustomerCapabilities::class)->listCustomers($context, CustomerListCommand::fromInput(['per_page' => 50])) : app(CatalogueCapabilities::class)->listItems($context, CatalogueListCommand::fromInput(['per_page' => 50]));
    try {
        $read();
        $one = count($sql);
        $observe = false;
        $model = $kind === 'customers' ? Customer::class : CatalogueItem::class;
        $model::factory()->count(49)->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id]);
        $sql = [];
        $observe = true;
        expect($read()->count())->toBe(50)->and(count($sql))->toBe($one)->and($one)->toBe(2);
        expect(implode(' ', $sql))->not->toContain('tax_identification_number')->not->toContain('email')->not->toContain('tracks_stock')->not->toContain('description');
    } finally {
        $observe = false;
    }
})->with(['customers', 'catalogue']);

test('invalid master storage returns no partial page success audit or last use', function (bool $external, string $kind) {
    $f = masterReadFixture();
    if ($kind === 'customers') {
        $f['customer']->update(['country_code' => 'zz']);
    } else {
        $f['item']->update(['currency_code' => 'usd']);
    }
    $this->actingAs($f['user'])->withHeader('Authorization', 'Bearer '.$f['secret']);
    $this->getJson(masterReadUrl($f, $kind, external: $external))->assertStatus(500)->assertDontSee('Cliente')->assertDontSee('usd');
    expect(Activity::where('event', $kind === 'customers' ? 'customers.list' : 'catalogue.list')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with([true, false])->with(['customers', 'catalogue']);

test('catalogue does not resolve customer specific or price list values', function () {
    $f = masterReadFixture();
    $ownership = ['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id];
    $list = PriceList::factory()->create($ownership);
    PriceListItem::factory()->create([...$ownership, 'price_list_id' => $list->id, 'catalogue_item_id' => $f['item']->id, 'unit_price_minor' => 222]);
    $f['customer']->update(['price_list_id' => $list->id]);
    CustomerPrice::factory()->create([...$ownership, 'customer_id' => $f['customer']->id, 'catalogue_item_id' => $f['item']->id, 'unit_price_minor' => 111]);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(masterReadUrl($f, 'catalogue', true))->assertOk()->assertJsonPath('data.unit_price_minor', '123456');
});
