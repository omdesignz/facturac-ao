<?php

use App\AgtEnvironment;
use App\Fiscal\CustomerCommands;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\ExternalCustomerCommand;
use App\Fiscal\HumanCustomerCommandContext;
use App\Fiscal\IntegrationCommandContext;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationLogRedactor;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
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

function commandHeaders(array $f, string $key = 'boundary'): array
{
    return ['Authorization' => 'Bearer '.$f['secret'], 'Idempotency-Key' => $key];
}

function commandBody(): array
{
    return ['name' => 'Cliente', 'tax_identification_number' => '001234567'];
}

test('review cancelled customer persistence never commits a successful command acknowledgement', function (string $event) {
    $f = commandFixture();
    $cancel = true;
    Customer::$event(function () use (&$cancel): ?bool {
        return $cancel ? false : null;
    });
    $this->postJson($f['url'], commandBody(), commandHeaders($f, 'cancel-save'))->assertStatus(503);
    expect(Customer::count())->toBe(0)
        ->and(DB::table('external_command_operations')->count())->toBe(0)
        ->and(DB::table('external_command_capacity')->count())->toBe(0)
        ->and(Activity::where('event', 'customer.created')->count())->toBe(0)
        ->and(Activity::where('event', 'external.command.authorized')->count())->toBe(0);
    $cancel = false;
    $this->postJson($f['url'], commandBody(), commandHeaders($f, 'cancel-save'))->assertCreated();
    expect(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1);
})->with(['saving', 'creating']);

test('review denied homologation commands retain their verified environment in audit', function () {
    $f = commandFixture();
    $issued = app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Read sandbox', ['documents:read']);
    $secret = $issued->revealOnce();
    $identity = IntegrationCommandContext::authenticate($secret, (string) Str::uuid());
    $queries = [];
    DB::connection()->beforeExecuting(function (string $sql) use (&$queries): void {
        $queries[] = $sql;
    });
    $this->postJson(str_replace('/production/', '/homologation/', $f['url']), commandBody(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'denied'])->assertForbidden();
    expect(implode(' ', $queries))->not->toContain('from "customers"')->not->toContain('from "price_lists"')->not->toContain('from "external_command_operations"');
    $audit = Activity::where('event', 'external.command.denied')->sole();
    expect($audit->properties['environment'])->toBe('homologation')
        ->and($audit->properties['integration_id'])->toBe($identity->integrationId)
        ->and($audit->properties['operation_id'])->toBeNull()
        ->and(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('review shared human creation refuses cancelled model persistence', function (string $event) {
    $f = commandFixture();
    $context = HumanCustomerCommandContext::resolve($f['user'], $f['entity']);
    $input = CustomerCreateInput::human([...commandBody(), 'country_code' => 'AO']);
    Customer::$event(fn (): bool => false);
    try {
        app(CustomerCommands::class)->create($context, $input);
        $this->fail('Cancelled customer was accepted.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }
    expect(Customer::count())->toBe(0)->and(Activity::where('event', 'customer.created')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with(['saving', 'creating']);

test('command defaults are intentional and acknowledgement cannot grow with model serialization', function () {
    $f = commandFixture();
    $response = $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $body = $response->json();
    expect(array_keys($body))->toBe(['data', 'meta'])->and(array_keys($body['data']))->toBe(['public_id'])
        ->and(array_keys($body['meta']))->toBe(['operation_id'])->and(Str::isUuid($body['meta']['operation_id']))->toBeTrue();
    $customer = Customer::query()->sole();
    expect($customer->tax_identification_number)->toBe('001234567')->and($customer->country_code)->toBe('AO')
        ->and($customer->is_active)->toBeTrue()->and($customer->payment_terms_days)->toBe(0)
        ->and($customer->auto_send_documents)->toBeFalse()->and($customer->credit_limit_minor)->toBeNull()
        ->and($customer->email)->toBeNull()->and($customer->phone)->toBeNull()->and($customer->address_line)->toBeNull()
        ->and($customer->withholding_type)->toBeNull()->and($customer->price_list_id)->toBeNull();
    $customer->makeVisible(['id', 'email', 'workspace_id']);
    $customer->forceFill(['email' => 'secret@example.test'])->save();
    $replay = $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    expect($replay->getContent())->toBe($response->getContent())->and($replay->headers->get('X-Request-ID'))->not->toBe($response->headers->get('X-Request-ID'));
    $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Authorization');
    expect($response->headers->has('Location'))->toBeFalse();
});

test('pure canonical bytes and digest have a stable independent golden vector', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external('{"name":"Cafe\u0301","tax_identification_number":" ab1234567 "}');
    $expected = '{"canonicalizer":"command-json-v1","command":"customers.create","context":{"environment":"production","legal_entity_public_id":"'.$f['context']->entityPublicId.'","workspace_public_id":"'.$f['context']->workspacePublicId.'"},"defaults":"customer-create-v1","input":{"country_code":"AO","name":"Café","tax_identification_number":"AB1234567"},"principal":{"kind":"integration","public_id":"'.strtolower($f['context']->integrationPublicId).'"},"version":1}';
    expect(app(ExternalCustomerCommand::class)->canonical($f['context'], $input))->toBe($expected);
    $service = app(ExternalCustomerCommand::class);
    $service->execute($f['context'], $input, 'golden');
    expect(DB::table('external_command_operations')->value('fingerprint_hash'))->toBe(hash('sha256', $expected));
    expect(fn () => json_encode($input, JSON_THROW_ON_ERROR))->toThrow(LogicException::class)
        ->and(fn () => serialize($f['context']))->toThrow(LogicException::class)
        ->and(fn () => json_encode($f['context'], JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
});

test('strict HTTP representation rejects transport and input ambiguity without effects', function (string $body, array $headers, string $suffix, int $status) {
    $f = commandFixture();
    $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret'], 'HTTP_IDEMPOTENCY_KEY' => 'strict', 'CONTENT_TYPE' => 'application/json', ...$headers];
    $r = $this->call('POST', $f['url'].$suffix, [], [], [], $server, $body)->assertStatus($status);
    expect(array_keys($r->json()))->toBe(['error', 'meta'])->and($r->json('error.message'))->toBe('Não foi possível concluir o pedido.')
        ->and(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with([
    ['{}', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '', 415],
    ['{}', ['CONTENT_TYPE' => 'application/json; charset=latin1'], '', 415],
    ['{}', ['HTTP_CONTENT_ENCODING' => 'gzip'], '', 415],
    [str_repeat('a', 4097), ['CONTENT_LENGTH' => '1'], '', 413],
    ['{}', [], '?x=1', 422], ['{}', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT'], '', 405],
    ['{}', ['HTTP_IDEMPOTENCY_KEY' => ''], '', 422], ['{}', ['HTTP_IDEMPOTENCY_KEY' => str_repeat('a', 129)], '', 422],
    ['{}', ['HTTP_IDEMPOTENCY_KEY' => 'é'], '', 422], ['{}', ['HTTP_IDEMPOTENCY_KEY' => "a\tb"], '', 422],
    ['{"name":"\ud800","tax_identification_number":"001234567"}', [], '', 400],
    ['{"name":"A","tax_identification_number":"001234567","deep":{"a":{"b":{"c":1}}}}', [], '', 400],
    ['{"name":"A","tax_identification_number":"001234567","country_code":null}', [], '', 422],
    ['{"name":"A","tax_identification_number":"001234567","public_id":"injected"}', [], '', 422],
]);

test('opaque keys preserve comma and case but strip only HTTP outer whitespace', function () {
    $f = commandFixture();
    $first = $this->postJson($f['url'], commandBody(), commandHeaders($f, ' A,B '))->assertCreated();
    $second = $this->postJson($f['url'], commandBody(), commandHeaders($f, 'A,B'))->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent());
    $this->postJson($f['url'], commandBody(), commandHeaders($f, 'a,b'))->assertStatus(409)->assertJsonPath('error.code', 'CUSTOMER_CONFLICT');
    expect(DB::table('external_command_operations')->value('key_hash'))->toBe(hash('sha256', 'A,B'));
});

test('all context substitutions deny before any domain or ledger query', function (string $kind) {
    $f = commandFixture();
    $path = match ($kind) {
        'workspace' => str_replace($f['context']->workspacePublicId, strtolower((string) Str::ulid()), $f['url']),
        'entity' => str_replace($f['context']->entityPublicId, strtolower((string) Str::ulid()), $f['url']),
        'environment' => str_replace('/production/', '/homologation/', $f['url']),
        'malformed' => str_replace($f['context']->entityPublicId, '123', $f['url']),
    };
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $this->postJson($path, commandBody(), commandHeaders($f))->assertStatus($kind === 'malformed' ? 422 : 403);
    expect(implode(' ', $queries))->not->toContain('from "customers"')->not->toContain('from "price_lists"')->not->toContain('from "external_command_operations"');
    expect(Customer::count())->toBe(0);
})->with(['workspace', 'entity', 'environment', 'malformed']);

test('browser state and cookies cannot confer or redirect command authority', function () {
    $f = commandFixture();
    $foreign = User::factory()->withWorkspace()->create();
    $f['user']->update(['current_workspace_id' => $foreign->current_workspace_id]);
    $this->actingAs($foreign)->withCookie('currentWorkspace', (string) $foreign->current_workspace_id)
        ->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    expect(Customer::query()->sole()->workspace_id)->toBe($f['context']->workspaceId);
    $this->postJson($f['url'], commandBody(), ['Idempotency-Key' => 'cookie-only'])->assertUnauthorized();
});

test('create scope grants none of the prior read resources', function (string $resource) {
    $f = commandFixture();
    $base = str_replace('/commands/v1/', str_starts_with($resource, 'v2:') ? '/v2/' : '/v1/', $f['url']);
    $resource = match ($resource) {
        'v2:agt' => 'documents/'.strtolower((string) Str::ulid()).'/agt-status', 'v2:billing' => 'analytics/billing-summary', default => $resource
    };
    $base = substr($base, 0, -strlen('customers')).$resource;
    $this->getJson($base, ['Authorization' => 'Bearer '.$f['secret']])->assertForbidden();
})->with(['customers', 'catalogue-items', 'documents', 'v2:agt', 'v2:billing']);

test('rotation replays stable operation with distinct current and origin attribution', function () {
    $f = commandFixture();
    $first = $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $replacement = app(IntegrationCredentials::class)->rotate($f['management'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId, ['customers:create'], immediateRevoke: true);
    $secret = $replacement->revealOnce();
    $current = IntegrationCommandContext::authenticate($secret, (string) Str::uuid());
    $second = $this->postJson($f['url'], commandBody(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'boundary'])->assertCreated();
    expect($second->getContent())->toBe($first->getContent());
    $audit = Activity::where('event', 'external.command.replayed')->sole()->properties;
    expect($audit['credential_id'])->toBe($current->credentialId)->and($audit['origin_credential_id'])->toBe($f['context']->credentialId)
        ->and($audit['origin_sponsor_attribution_id'])->toBe($f['user']->attribution_id)->and($audit['user_agent'])->toBeNull();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertUnauthorized();
});

test('default price selection is scoped and ambiguous defaults fail without reserving a key', function (int $defaults) {
    $f = commandFixture();
    for ($i = 0; $i < $defaults; $i++) {
        PriceList::factory()->create(['workspace_id' => $f['context']->workspaceId, 'legal_entity_id' => $f['entity']->id, 'is_default' => true, 'is_active' => false]);
    }
    $foreign = commandFixture();
    PriceList::factory()->create(['workspace_id' => $foreign['context']->workspaceId, 'legal_entity_id' => $foreign['entity']->id, 'is_default' => true, 'name' => 'Foreign']);
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertStatus($defaults > 1 ? 503 : 201);
    if ($defaults === 1) {
        expect(Customer::query()->sole()->price_list_id)->toBe(PriceList::where('legal_entity_id', $f['entity']->id)->value('id'));
    }
    expect(Customer::count())->toBe($defaults > 1 ? 0 : 1)->and(DB::table('external_command_operations')->count())->toBe($defaults > 1 ? 0 : 1);
})->with([0, 1, 2]);

test('audit never stores customer input keys results or ambient headers and replay audit is required', function () {
    $f = commandFixture();
    $headers = [...commandHeaders($f), 'User-Agent' => 'private-agent-string', 'X-Client-Request-ID' => 'bounded.client'];
    $this->postJson($f['url'], commandBody(), $headers)->assertCreated();
    $text = Activity::whereIn('event', ['external.command.attempted', 'external.command.authorized', 'customer.created'])->get()->toJson();
    expect($text)->not->toContain('001234567')->not->toContain('Cliente')->not->toContain('private-agent-string')->not->toContain($f['secret'])->not->toContain('key_hash')->not->toContain('fingerprint_hash')->not->toContain('response_body');
    Activity::creating(function (Activity $a) {
        if ($a->event === 'external.command.replayed') {
            return false;
        }
    });
    $this->postJson($f['url'], commandBody(), $headers)->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    expect(Customer::count())->toBe(1)->and(Activity::where('event', 'customer.created')->count())->toBe(1);
});

test('normative OpenAPI route scope schemas and status table match the runtime boundary', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-customer-create-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($spec['paths']))->toBe(['/api/integrations/commands/v1/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/customers']);
    $post = array_values($spec['paths'])[0]['post'];
    $statuses = array_keys($post['responses']);
    sort($statuses);
    expect($statuses)->toBe([201, 400, 401, 403, 404, 405, 409, 413, 415, 422, 429, 503]);
    $f = commandFixture();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    expect(config('integrations.commands_enabled'))->toBeTrue();
});

test('command write quota charges normalized aliases replays conflicts and unsupported methods', function () {
    $f = commandFixture();
    while (time() % 60 > 40) {
        usleep(100000);
    }
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $this->postJson($f['url'], [...commandBody(), 'name' => 'Different'], commandHeaders($f))->assertStatus(409);
    $this->postJson($f['url'].'?ignored=1', [], commandHeaders($f))->assertStatus(422);
    $this->getJson($f['url'], commandHeaders($f))->assertStatus(405);
    $this->call('HEAD', $f['url'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret']])->assertStatus(405)->assertContent('');
    for ($i = 0; $i < 4; $i++) {
        $this->postJson($f['url'], ['name' => 'Missing NIF'], commandHeaders($f, 'invalid-'.$i))->assertStatus(422);
    }
    $this->postJson(str_replace('/customers', '/%63ustomers', $f['url']), commandBody(), commandHeaders($f))->assertStatus(429)->assertHeader('Retry-After');
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1);
});

test('command IP admission bounds invalid bearer attempts without generating evidence', function () {
    $f = commandFixture();
    while (time() % 60 > 40) {
        usleep(100000);
    }
    for ($i = 0; $i < 60; $i++) {
        $this->postJson($f['url'], commandBody(), ['Authorization' => 'Bearer unknown', 'Idempotency-Key' => 'ip'])->assertUnauthorized();
    }
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertStatus(429);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('command workspace budget cannot be reset by creating or rotating another identity', function () {
    $f = commandFixture();
    $secrets = [$f['secret']];
    for ($i = 0; $i < 3; $i++) {
        $secrets[] = app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Production, 'Quota'.$i, ['customers:create'])->revealOnce();
    }
    while (time() % 60 > 40) {
        usleep(100000);
    }
    for ($i = 0; $i < 30; $i++) {
        $this->postJson($f['url'], ['name' => 'Missing NIF'], ['Authorization' => 'Bearer '.$secrets[$i % 4], 'Idempotency-Key' => 'invalid'])->assertStatus(422);
    }
    $this->postJson($f['url'], commandBody(), ['Authorization' => 'Bearer '.$secrets[3], 'Idempotency-Key' => 'new'])->assertStatus(429);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('detectable repeated idempotency fields are rejected rather than merged', function () {
    $f = commandFixture();
    $request = Request::create($f['url'], 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret'], 'CONTENT_TYPE' => 'application/json'], json_encode(commandBody(), JSON_THROW_ON_ERROR));
    $request->headers->set('Idempotency-Key', ['one', 'two']);
    $response = app(Kernel::class)->handle($request);
    expect($response->getStatusCode())->toBe(400)->and(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('an inactive customer or changed country is still a domain duplicate while different entities remain independent', function () {
    $f = commandFixture();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    Customer::query()->sole()->update(['is_active' => false, 'country_code' => 'PT']);
    $this->postJson($f['url'], commandBody(), commandHeaders($f, 'new'))->assertStatus(409)->assertJsonPath('error.code', 'CUSTOMER_CONFLICT');
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $f['context']->workspaceId]);
    $issued = app(IntegrationCredentials::class)->create($f['management'], $entity->public_id, AgtEnvironment::Production, 'Other entity', ['customers:create']);
    $secret = $issued->revealOnce();
    $url = str_replace($f['context']->entityPublicId, $entity->public_id, $f['url']);
    $this->postJson($url, commandBody(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'boundary'])->assertCreated();
    expect(Customer::count())->toBe(2);
});

test('Unicode limits and all three normalized fields preserve only signed equivalences', function () {
    $f = commandFixture();
    $service = app(ExternalCustomerCommand::class);
    $a = CustomerCreateInput::external(json_encode(['name' => str_repeat('é', 255), 'tax_identification_number' => ' ab1234567 ', 'country_code' => ' ao '], JSON_THROW_ON_ERROR));
    expect(mb_strlen($a->attributes['name']))->toBe(255);
    expect(fn () => CustomerCreateInput::external(json_encode(['name' => str_repeat('é', 256), 'tax_identification_number' => 'AB1234567'], JSON_THROW_ON_ERROR)))->toThrow(HttpException::class);
    $base = CustomerCreateInput::external('{"name":"A  B","tax_identification_number":"AB1234567"}');
    $same = CustomerCreateInput::external('{"country_code":"AO","name":" \tA  B\r\n","tax_identification_number":"AB1234567"}');
    expect($service->canonical($f['context'], $base))->toBe($service->canonical($f['context'], $same));
    foreach ([['name' => 'A B'], ['name' => 'a  b'], ['tax_identification_number' => 'AB1234568'], ['country_code' => 'PT']] as $change) {
        $other = CustomerCreateInput::external(json_encode([...['name' => 'A  B', 'tax_identification_number' => 'AB1234567'], ...$change], JSON_THROW_ON_ERROR));
        expect($service->canonical($f['context'], $base))->not->toBe($service->canonical($f['context'], $other));
    }
});

test('machine execution cannot accept the broader human form DTO or bypass its command reservation', function () {
    $f = commandFixture();
    $human = CustomerCreateInput::human([...commandBody(), 'country_code' => 'AO', 'email' => 'private@example.test']);
    expect(fn () => app(ExternalCustomerCommand::class)->execute($f['context'], $human, 'human-dto'))->toThrow(HttpException::class);
    $external = CustomerCreateInput::external(json_encode(commandBody(), JSON_THROW_ON_ERROR));
    expect(fn () => DB::transaction(fn () => app(CustomerCommands::class)->persist($f['context'], $external)))->toThrow(HttpException::class);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('homologation identity cannot inherit production create even from a malformed database grant', function () {
    $f = commandFixture();
    expect(fn () => app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Forbidden', ['customers:create']))->toThrow(HttpException::class);
    $issued = app(IntegrationCredentials::class)->create($f['management'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Read-only', ['documents:read']);
    $secret = $issued->revealOnce();
    $context = IntegrationCommandContext::authenticate($secret, (string) Str::uuid());
    DB::table('integration_scopes')->insert(['integration_id' => $context->integrationId, 'scope' => 'customers:create']);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $context->credentialId, 'scope' => 'customers:create']);
    $this->postJson($f['url'], commandBody(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'tampered'])->assertForbidden();
    expect(fn () => app(IntegrationCredentials::class)->rotate($f['management'], $issued->integrationPublicId, 1, $issued->credentialPublicId, ['customers:create']))->toThrow(HttpException::class);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
});

test('deleted customer and changed pricing defaults do not alter or recreate historical acknowledgement', function () {
    $f = commandFixture();
    $first = $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    Customer::query()->sole()->delete();
    for ($i = 0; $i < 2; $i++) {
        PriceList::factory()->create(['workspace_id' => $f['context']->workspaceId, 'legal_entity_id' => $f['entity']->id, 'is_default' => true]);
    }
    $second = $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent())->and(Customer::count())->toBe(0)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
});

test('disabled buffered and throwing required audits deny any successful mutation', function (string $mode) {
    $f = commandFixture();
    if ($mode === 'disabled') {
        config(['activitylog.enabled' => false]);
    }
    if ($mode === 'buffered') {
        config(['activitylog.buffer.enabled' => true]);
    }
    if ($mode === 'throwing') {
        Activity::creating(fn () => throw new RuntimeException('Unsafe audit fault'));
    }
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertStatus(503)->assertJsonPath('error.message', 'Não foi possível concluir o pedido.');
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0);
})->with(['disabled', 'buffered', 'throwing']);

test('command logging redacts typed data keys fingerprints and customer database bindings', function () {
    $f = commandFixture();
    $input = CustomerCreateInput::external(json_encode(commandBody(), JSON_THROW_ON_ERROR));
    $redacted = IntegrationLogRedactor::redact(['input' => $input, 'context' => $f['context'], 'idempotency-key' => 'private-key', 'command_key' => 'private-key', 'fingerprint_hash' => 'private-hash']);
    expect(json_encode($redacted, JSON_THROW_ON_ERROR))->not->toContain('Cliente')->not->toContain('001234567')->not->toContain('private-key')->not->toContain('private-hash');
    DB::enableQueryLog();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $this->postJson($f['url'], commandBody(), commandHeaders($f, 'duplicate'))->assertStatus(409);
    $log = json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR);
    DB::disableQueryLog();
    expect($log)->not->toContain('Cliente')->not->toContain('001234567')->not->toContain('private-key');
});

class CommandOtherConnectionActivity extends Activity
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection('command_audit_other');
    }
}

test('wrong-connection required audit is rejected before it can store a false success', function () {
    $f = commandFixture();
    config(['database.connections.command_audit_other' => config('database.connections.'.DB::getDefaultConnection()), 'activitylog.activity_model' => CommandOtherConnectionActivity::class]);
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertStatus(503);
    expect(Customer::count())->toBe(0)->and(DB::table('external_command_operations')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'customer.created')->count())->toBe(0);
});

test('a replacement membership cannot restore an integration sponsored by the original membership', function () {
    $f = commandFixture();
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    WorkspaceMembership::findOrFail($f['context']->membershipId)->delete();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['context']->workspaceId, 'user_id' => $f['user']->id, 'role' => 'owner', 'is_active' => true]);
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertForbidden();
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1);
});

test('narrowed replacement credential cannot replay a historical create result', function () {
    $f = commandFixture(['customers:create', 'customers:read']);
    $this->postJson($f['url'], commandBody(), commandHeaders($f))->assertCreated();
    $issued = app(IntegrationCredentials::class)->rotate($f['management'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId, ['customers:read'], immediateRevoke: true);
    $secret = $issued->revealOnce();
    $this->postJson($f['url'], commandBody(), ['Authorization' => 'Bearer '.$secret, 'Idempotency-Key' => 'boundary'])->assertForbidden();
    expect(Customer::count())->toBe(1)->and(DB::table('external_command_operations')->count())->toBe(1)
        ->and((int) DB::table('external_command_capacity')->value('completed_count'))->toBe(1);
});

test('one and 128 byte keys and the exact body size limit accept then replay', function (string $key) {
    $f = commandFixture();
    $body = json_encode(commandBody(), JSON_THROW_ON_ERROR);
    $body .= str_repeat(' ', 4096 - strlen($body));
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$f['secret'], 'HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json; charset=utf-8'];
    $first = $this->call('POST', $f['url'], [], [], [], $headers, $body)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->call('POST', $f['url'], [], [], [], $headers, $body)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($first->getContent())->toBe($second->getContent())->and(Customer::count())->toBe(1);
})->with(['a', str_repeat('a', 128)]);
