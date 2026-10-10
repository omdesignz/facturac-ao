<?php

use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentListCommand;
use App\Fiscal\IntegrationLogRedactor;
use App\Fiscal\IntegrationReadContext;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array{integration: Integration, credential: IntegrationCredential, document: FiscalDocument, secret: string, url: string} */
function externalFixture(string $environment = 'homologation'): array
{
    $integration = Integration::factory()->create(['environment' => $environment]);
    $selector = (string) Str::ulid();
    $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $secret)]);
    DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'documents:read']);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'documents:read']);
    $document = FiscalDocument::factory()->create(['environment' => $environment, 'workspace_id' => $integration->workspace_id, 'legal_entity_id' => $integration->legal_entity_id, 'created_by_user_id' => $integration->sponsor_user_id]);
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $url = route('integrations.v1.documents.show', ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => $environment, 'documentPublicId' => $document->public_id]);

    return compact('integration', 'credential', 'document', 'secret', 'url');
}

beforeEach(function () {
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

test('external reads are disabled by default and cookies never authenticate them', function () {
    $fixture = externalFixture();
    config(['integrations.enabled' => false]);
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret'])->getJson($fixture['url'])->assertNotFound();
    config(['integrations.enabled' => true]);
    $this->flushHeaders()->actingAs(User::findOrFail($fixture['integration']->sponsor_user_id))->getJson($fixture['url'])->assertUnauthorized();
});

test('external safe reads audit machine identity and ignore browser and untrusted ambient attribution', function () {
    $fixture = externalFixture();
    $this->actingAs(User::factory()->withWorkspace()->create());
    Context::add(['impersonator_id' => 555, 'workspace_id' => 999]);
    $before = $fixture['document']->fresh()->getAttributes();
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$fixture['secret'], 'User-Agent' => $fixture['secret'], 'X-Request-ID' => 'fake', 'X-Client-Request-ID' => 'client-123'])->getJson($fixture['url'])
        ->assertOk()->assertJsonPath('data.public_id', $fixture['document']->public_id)->assertJsonCount(10, 'data');
    $audit = Activity::where('event', 'documents.read')->latest('id')->firstOrFail();
    expect($audit->properties['actor_kind'])->toBe('integration')->and($audit->causer_type)->toBe(Integration::class)
        ->and($audit->properties['authority_user_id'])->toBe($fixture['integration']->sponsor_user_id)
        ->and($audit->properties['request_id'])->toBe($response->json('meta.request_id'))->not->toBe('fake')
        ->and($audit->properties['client_request_id'])->toBe('client-123')
        ->and(json_encode($audit->properties))->not->toContain($fixture['secret'])->and($fixture['credential']->fresh()->last_used_at)->not->toBeNull()
        ->and($fixture['document']->fresh()->getAttributes())->toBe($before)->and($response->headers->getCookies())->toBe([]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

test('invalid credentials share a single non-enumerating failure contract', function (string $condition) {
    $fixture = externalFixture();
    $secret = $fixture['secret'];
    if ($condition === 'unknown') {
        $secret = str_replace($fixture['credential']->public_id, (string) Str::ulid(), $secret);
    }
    if ($condition === 'wrong') {
        $secret = substr($secret, 0, -1).($secret[-1] === 'a' ? 'b' : 'a');
    }
    if ($condition === 'malformed') {
        $secret = 'bad';
    }
    if ($condition === 'expired') {
        $fixture['credential']->forceFill(['expires_at' => now()->subSecond(), 'created_at' => now()->subDay()])->save();
    }
    if ($condition === 'revoked') {
        $fixture['credential']->update(['revoked_at' => now()]);
    }
    if ($condition === 'integration-revoked') {
        $fixture['integration']->update(['revoked_at' => now()]);
    }
    $this->withHeader('Authorization', 'Bearer '.$secret)->getJson($fixture['url'])->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED')->assertHeader('WWW-Authenticate', 'Bearer');
    expect(Activity::where('event', 'documents.read')->count())->toBe(0);
})->with(['unknown', 'wrong', 'malformed', 'expired', 'revoked', 'integration-revoked']);

test('direct capability contexts revalidate current machine and sponsor authority', function (string $condition) {
    $fixture = externalFixture();
    $context = IntegrationReadContext::authenticate($fixture['secret'], (string) Str::uuid());
    if ($condition === 'credential') {
        $fixture['credential']->update(['revoked_at' => now()]);
    }
    if ($condition === 'integration') {
        $fixture['integration']->update(['revoked_at' => now()]);
    }
    if ($condition === 'scope') {
        DB::table('integration_scopes')->where('integration_id', $fixture['integration']->id)->delete();
    }
    if ($condition === 'membership') {
        WorkspaceMembership::where('id', $fixture['integration']->sponsor_membership_id)->update(['is_active' => false]);
    }
    if ($condition === 'role') {
        WorkspaceMembership::where('id', $fixture['integration']->sponsor_membership_id)->update(['role' => 'viewer']);
    }
    if ($condition === 'mfa') {
        User::findOrFail($fixture['integration']->sponsor_user_id)->forceFill(['two_factor_confirmed_at' => null])->save();
    }
    if ($condition === 'verified') {
        User::findOrFail($fixture['integration']->sponsor_user_id)->forceFill(['email_verified_at' => null])->save();
    }
    expect(fn () => app(DocumentCapabilities::class)->listDocuments($context, DocumentListCommand::fromInput([])))->toThrow(HttpException::class);
    expect(fn () => $context->authorize('fiscal.issue'))->toThrow(HttpException::class);
})->with(['credential', 'integration', 'scope', 'membership', 'role', 'mfa', 'verified']);

test('well formed foreign and absent execution contexts are indistinguishable', function () {
    $fixture = externalFixture();
    $other = externalFixture();
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret']);
    $this->getJson($other['url'])->assertForbidden();
    $this->getJson(str_replace(Workspace::findOrFail($other['integration']->workspace_id)->public_id, (string) Str::ulid(), $other['url']))->assertForbidden();
    $this->getJson(str_replace('/homologation/', '/production/', $fixture['url']))->assertForbidden();
    $this->getJson(str_replace($fixture['document']->public_id, $other['document']->public_id, $fixture['url']))->assertNotFound();
    $this->getJson(str_replace($fixture['document']->public_id, (string) Str::ulid(), $fixture['url']))->assertNotFound();
});

test('external HEAD and lowercase route identities retain scoped read behavior', function () {
    $fixture = externalFixture();
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret']);
    $this->getJson(strtolower($fixture['url']))->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret'])->head($fixture['url'])->assertOk()->assertContent('');
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret'])->head(str_replace('/homologation/', '/production/', $fixture['url']))->assertForbidden()->assertContent('');
});

test('unknown filters and delegation inputs cannot expand authority', function (string $input) {
    $fixture = externalFixture();
    $list = substr($fixture['url'], 0, strrpos($fixture['url'], '/'));
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret'])->getJson($list.'?'.$input)->assertUnprocessable();
    expect(Activity::where('event', 'documents.list')->count())->toBe(0);
})->with(['scope=*', 'agent_id=1', 'on_behalf_of=2', 'per_page=51', 'permissions[]=fiscal.issue']);

test('external root and audit failures have sanitized debug-safe errors', function () {
    $fixture = externalFixture();
    config(['app.debug' => true]);
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret'])->getJson('/api/integrations/v1')->assertNotFound()->assertDontSee('trace');
    Activity::creating(fn () => throw new RuntimeException('private-detail-'.$fixture['secret']));
    try {
        $this->getJson($fixture['url'])->assertStatus(500)->assertDontSee($fixture['secret'])->assertDontSee('private-detail');
    } finally {
        Activity::flushEventListeners();
    }
});

test('shared limiter rejects unsafe stores and two credentials share the integration quota', function () {
    $fixture = externalFixture();
    $this->withHeader('Authorization', 'Bearer '.$fixture['secret']);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson($fixture['url'])->assertOk();
    }
    $selector = (string) Str::ulid();
    $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $second = IntegrationCredential::factory()->create(['integration_id' => $fixture['integration']->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $secret)]);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $second->id, 'scope' => 'documents:read']);
    $this->withHeader('Authorization', 'Bearer '.$secret)->getJson($fixture['url'])->assertStatus(429)->assertHeader('Retry-After');
    expect($second->fresh()->last_used_at)->toBeNull();
    config(['integrations.cache_store' => 'array']);
    $this->getJson($fixture['url'])->assertStatus(503);
});

test('invalid client correlation is refused without echoing secret material into audit', function () {
    $f = externalFixture();
    $this->withHeaders(['Authorization' => 'Bearer '.$f['secret'], 'X-Client-Request-ID' => $f['secret']])->getJson($f['url'])
        ->assertUnprocessable()->assertDontSee($f['secret']);
    expect(json_encode(Activity::all()))->not->toContain($f['secret']);
});

test('temporary sponsor ineligibility restores only live grants while revocation remains terminal', function () {
    $f = externalFixture();
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['is_active' => false]);
    expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
    WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['is_active' => true]);
    $context->authorize('documents.read');
    $f['credential']->update(['revoked_at' => now()]);
    expect(fn () => DB::table('integration_credentials')->where('id', $f['credential']->id)->update(['revoked_at' => null]))->toThrow(QueryException::class);
});

test('scope ceiling membership recreation expiry and sibling entities cannot resurrect authority', function (string $condition) {
    $f = externalFixture();
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    if ($condition === 'credential-scope') {
        DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->delete();
    }
    if ($condition === 'expiry') {
        $f['credential']->forceFill(['created_at' => now()->subDay(), 'expires_at' => now()->subSecond()])->save();
    }
    if ($condition === 'membership-recreate') {
        DB::table('workspace_memberships')->where('id', $f['integration']->sponsor_membership_id)->delete();
        WorkspaceMembership::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'user_id' => $f['integration']->sponsor_user_id]);
    }
    expect(fn () => app(DocumentCapabilities::class)->read($context, $f['document']->public_id))->toThrow(HttpException::class);
})->with(['credential-scope', 'expiry', 'membership-recreate']);

test('list is bounded excludes quarantined and sibling records and has constant read query count', function () {
    $f = externalFixture();
    $other = externalFixture();
    $documents = FiscalDocument::factory()->count(8)->create(['establishment_id' => $f['document']->establishment_id, 'workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $f['integration']->legal_entity_id, 'created_by_user_id' => $f['integration']->sponsor_user_id]);
    $documents[0]->update(['environment' => 'unresolved']);
    $url = substr($f['url'], 0, strrpos($f['url'], '/'));
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($url.'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 8);
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    DB::enableQueryLog();
    app(DocumentCapabilities::class)->listDocuments($context, DocumentListCommand::fromInput(['per_page' => 2]));
    $small = count(DB::getQueryLog());
    DB::flushQueryLog();
    app(DocumentCapabilities::class)->listDocuments($context, DocumentListCommand::fromInput(['per_page' => 50]));
    expect(count(DB::getQueryLog()))->toBe($small);
    DB::disableQueryLog();
});

test('unsupported methods and delegation headers cannot dispatch a consequential capability', function () {
    $f = externalFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->postJson($f['url'])->assertStatus(405)->assertHeader('Allow');
    $this->withHeader('X-On-Behalf-Of', '123')->getJson($f['url'])->assertUnprocessable();
    expect(Activity::where('event', 'documents.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
});

test('field and prefix redaction prevents accidental log persistence of integration secrets', function () {
    $f = externalFixture();
    $logger = new Logger('test');
    $handler = new TestHandler;
    $logger->pushHandler($handler);
    (new IntegrationLogRedactor)(new Illuminate\Log\Logger($logger));
    $logger->warning('Unknown '.$f['secret'], ['Authorization' => 'Bearer '.$f['secret'], 'exception' => new RuntimeException('credential '.$f['secret']), 'url' => 'https://example.test/?private=opaque', 'nested' => ['secret_hash' => $f['credential']->secret_hash, 'detail' => $f['secret']]]);
    $record = $handler->getRecords()[0];
    expect((new JsonFormatter)->format($record))->not->toContain($f['secret'])->not->toContain($f['credential']->secret_hash);
});

test('anonymous IP quota cannot be multiplied with untrusted forwarded network headers', function () {
    $f = externalFixture();
    for ($i = 0; $i < 120; $i++) {
        $this->withHeader('X-Forwarded-For', '192.0.2.'.($i + 1))->getJson($f['url'])->assertUnauthorized();
    }
    $this->withHeader('X-Forwarded-For', '198.51.100.1')->getJson($f['url'])->assertStatus(429);
    expect(Activity::where('event', 'documents.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
});

test('shared store outage is sanitized and external OpenAPI contains only the approved read boundary', function () {
    $f = externalFixture();
    Schema::drop('cache');
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'])->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertDontSee('SQLSTATE');
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-read-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($spec['paths'])->toHaveCount(6)->and(array_keys($spec['components']['securitySchemes']))->toBe(['integrationBearer']);
    foreach ($spec['paths'] as $path => $operations) {
        expect($path)->toStartWith('/api/integrations/v1/')->and(array_keys($operations))->toBe(['get', 'head']);
        foreach ($operations['head']['responses'] as $response) {
            expect($response)->not->toHaveKey('content');
        }
    }
    expect($spec['components']['schemas']['Document']['properties'])->toHaveCount(10);
});

test('production-bound reads expose existing evidence without enabling outbound AGT', function () {
    $f = externalFixture('production');
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'])->assertOk()->assertJsonPath('meta.environment', 'production');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('TLS and rate-limit network identity honor only explicitly trusted proxies', function () {
    $f = externalFixture();
    TrustProxies::at(['127.0.0.1']);
    try {
        $url = str_replace('https:', 'http:', $f['url']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off'])->withHeaders(['Authorization' => 'Bearer '.$f['secret'], 'X-Forwarded-Proto' => 'https'])->getJson($url)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10', 'HTTPS' => 'off'])->getJson($url)->assertForbidden();
    } finally {
        TrustProxies::flushState();
    }
});
