<?php

use App\AgtEnvironment;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\ExecutionContext;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

/** @return array<string, mixed> */
function reviewedQualifiedFixture(): array
{
    $integration = Integration::factory()->create(['environment' => 'production']);
    $selector = (string) Str::ulid();
    $bearer = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $bearer)]);
    DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'documents:agt-status:read']);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'documents:agt-status:read']);
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $document = FiscalDocument::factory()->issued()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'production']);
    $parameters = ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => 'production', 'documentPublicId' => $document->public_id];
    $url = route('integrations.v2.documents.agt-status.show', $parameters);

    return compact('integration', 'credential', 'entity', 'document', 'parameters', 'url', 'bearer');
}

test('qualified human capability suppresses ambient request content in durable audit', function (string $method) {
    $f = reviewedQualifiedFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    $request = Request::create('/internal-read?secret=PRIVATE-QUERY', $method);
    $request->headers->set('User-Agent', 'PRIVATE-CONTACT '.$f['bearer']);
    $this->app->instance('request', $request);
    $console = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $prior = $console->getValue($this->app);
    $console->setValue($this->app, false);
    try {
        $data = app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id));
    } finally {
        $console->setValue($this->app, $prior);
    }
    $audit = Activity::where('event', 'documents.agt-status.read')->firstOrFail();
    expect($audit->properties->get('user_agent'))->toBeNull()
        ->and($audit->properties->toJson())->not->toContain('PRIVATE-CONTACT', 'PRIVATE-QUERY', $f['bearer'])
        ->and($audit->properties['method'])->toBe($method)
        ->and($audit->properties['effective_actor_id'])->toBe($user->id)
        ->and(json_encode($data))->not->toContain('PRIVATE-CONTACT', $f['bearer']);
})->with(['GET', 'HEAD']);

test('qualified normalized route aliases retain unsupported-method audit and protocol', function (string $variant, string $method) {
    $f = reviewedQualifiedFixture();
    $url = match ($variant) {
        'encoded-literal' => str_replace('agt-status', 'agt%2Dstatus', $f['url']),
        'encoded-prefix' => str_replace('/v2/', '/v%32/', $f['url']),
        'trailing-slash' => $f['url'].'/',
    };
    $this->withHeader('Authorization', 'Bearer '.$f['bearer'])->json($method, $url)
        ->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    $audit = Activity::where('event', 'documents.agt-status.read.denied')->firstOrFail();
    expect($audit->properties['capability_version'])->toBe(2)->and($audit->properties['operation'])->toBe('documents.agt-status.read')
        ->and($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['encoded-literal', 'encoded-prefix', 'trailing-slash'])->with(['OPTIONS', 'POST']);

test('qualified sponsor independent membership never broadens immutable credential context', function (string $method, bool $sameWorkspace) {
    $f = reviewedQualifiedFixture();
    $entity = LegalEntity::factory()->configured()->create($sameWorkspace ? ['workspace_id' => $f['entity']->workspace_id] : []);
    if (! $sameWorkspace) {
        WorkspaceMembership::factory()->create(['workspace_id' => $entity->workspace_id, 'user_id' => $f['integration']->sponsor_user_id, 'role' => 'owner', 'is_active' => true]);
    }
    $document = FiscalDocument::factory()->issued()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'production']);
    $queryCount = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$queryCount): void {
        if (str_contains($sql, 'fiscal_documents')) {
            $queryCount++;
        }
    });
    $parameters = [...$f['parameters'], 'workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'documentPublicId' => $document->public_id];
    $response = $this->withHeader('Authorization', 'Bearer '.$f['bearer'])->json($method, route('integrations.v2.documents.agt-status.show', $parameters))->assertForbidden();
    expect($queryCount)->toBe(0)->and($response->headers->get('Cache-Control'))->toBe('no-store, private');
    if ($method === 'HEAD') {
        expect($response->getContent())->toBe('');
    }
    expect(Activity::where('event', 'documents.agt-status.read.denied')->firstOrFail()->properties->toJson())->not->toContain($entity->public_id, $document->public_id);
})->with(['GET', 'HEAD'])->with([true, false]);

test('qualified cancelled audit insert withholds data and last use', function (string $method) {
    $f = reviewedQualifiedFixture();
    Activity::creating(fn () => false);
    try {
        $response = $this->withHeader('Authorization', 'Bearer '.$f['bearer'])->json($method, $f['url'])->assertStatus(503);
        expect(Activity::where('event', 'documents.agt-status.read')->count())->toBe(0)
            ->and($f['credential']->fresh()->last_used_at)->toBeNull();
        if ($method === 'HEAD') {
            expect($response->getContent())->toBe('');
        }
    } finally {
        Activity::flushEventListeners();
    }
})->with(['GET', 'HEAD']);

test('qualified GET HEAD normalized routes share one stable integration quota identity', function () {
    $f = reviewedQualifiedFixture();
    $urls = [$f['url'], str_replace('agt-status', 'agt%2Dstatus', $f['url']), str_replace('/v2/', '/v%32/', $f['url'])];
    $firstWindow = intdiv(time(), 60);
    foreach ($urls as $url) {
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->withHeader('Authorization', 'Bearer '.$f['bearer'])->json($method, $url)->assertOk();
            if ($method === 'HEAD') {
                expect($response->getContent())->toBe('');
            }
        }
    }
    $total = 0;
    foreach (range($firstWindow, intdiv(time(), 60)) as $window) {
        $total += Cache::store(config('integrations.cache_store'))
            ->get('external-read:'.hash('sha256', 'integration:'.$f['integration']->id).':'.$window, 0);
    }
    expect($total)->toBe(6)->and(Activity::where('event', 'documents.agt-status.read')->count())->toBe(6);
});
