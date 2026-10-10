<?php

use App\Fiscal\IntegrationLogRedactor;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['integrations.enabled' => true]);
    $this->integration = Integration::factory()->create();
    $selector = (string) Str::ulid();
    $this->secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $this->credential = IntegrationCredential::factory()->create(['integration_id' => $this->integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $this->secret)]);
    DB::table('integration_scopes')->insert(['integration_id' => $this->integration->id, 'scope' => 'documents:read']);
    DB::table('integration_credential_scopes')->insert(['credential_id' => $this->credential->id, 'scope' => 'documents:read']);
    $this->document = FiscalDocument::factory()->create(['workspace_id' => $this->integration->workspace_id, 'legal_entity_id' => $this->integration->legal_entity_id]);
    $entity = LegalEntity::findOrFail($this->integration->legal_entity_id);
    $this->url = route('integrations.v1.documents.show', ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => 'homologation', 'documentPublicId' => $this->document->public_id]);
    $this->withHeader('Authorization', 'Bearer '.$this->secret);
});

test('review rejects body credentials and delegation on both read methods', function (string $method, bool $list) {
    $url = $list ? substr($this->url, 0, strrpos($this->url, '/')) : $this->url;
    $response = $this->json($method, $url, ['access_token' => $this->secret, 'on_behalf_of' => 1])->assertUnprocessable();
    if ($method === 'HEAD') {
        $response->assertContent('');
    }
    expect(Activity::whereIn('event', ['documents.read', 'documents.list'])->count())->toBe(0)
        ->and($this->credential->fresh()->last_used_at)->toBeNull()
        ->and(json_encode(Activity::all()))->not->toContain($this->secret);
})->with([['GET', false], ['GET', true], ['HEAD', false], ['HEAD', true]]);

test('review requires durable audit before returning data or updating last use', function (string $mode) {
    if ($mode === 'disabled') {
        activity()->disableLogging();
    } elseif ($mode === 'buffered') {
        config(['activitylog.buffer.enabled' => true]);
    } else {
        Activity::creating(fn () => false);
    }
    try {
        $this->getJson($this->url)->assertStatus(500)->assertJsonMissingPath('data');
        expect($this->credential->fresh()->last_used_at)->toBeNull();
    } finally {
        activity()->enableLogging();
        config(['activitylog.buffer.enabled' => false]);
        Activity::flushEventListeners();
    }
})->with(['disabled', 'buffered', 'cancelled']);

test('review records authenticated unsupported method denial without browser identity', function () {
    $this->postJson($this->url)->assertStatus(405);
    $event = Activity::where('event', 'documents.read.denied')->firstOrFail();
    expect($event->causer_type)->toBe(Integration::class)->and($event->causer_id)->toBe($this->integration->id)
        ->and($event->properties['human_actor_id'])->toBeNull()->and($event->properties['error_code'])->toBe('METHOD_NOT_ALLOWED');
});

test('review redacts nested exception chains and database row objects', function (string $shape) {
    $logger = new Logger('review');
    $handler = new TestHandler;
    $logger->pushHandler($handler);
    (new IntegrationLogRedactor)(new Illuminate\Log\Logger($logger));
    $value = match ($shape) {
        'chain' => new RuntimeException('Outer failure', 0, new RuntimeException($this->secret)),
        'digest' => new RuntimeException('Outer failure', 0, new RuntimeException('secret_hash '.$this->credential->secret_hash)),
        'row' => (object) ['nested' => (object) ['secret_hash' => $this->credential->secret_hash, 'detail' => $this->secret]],
    };
    $logger->warning('Integration failure', ['detail' => $value]);
    $formatted = (new JsonFormatter)->format($handler->getRecords()[0]);
    expect($formatted)->not->toContain($this->secret)->not->toContain($this->credential->secret_hash);
})->with(['chain', 'digest', 'row']);

test('review rejects malformed combined duplicated and oversized bearer headers uniformly', function (string $shape) {
    $header = match ($shape) {
        'truncated' => 'Bearer '.substr($this->secret, 0, -1),
        'oversized' => 'Bearer '.str_repeat('a', 257),
        'combined' => 'Bearer '.$this->secret.', Bearer '.$this->secret,
        'duplicate' => ['Bearer '.$this->secret, 'Bearer '.$this->secret],
        'lowercase-selector' => 'Bearer '.strtolower(substr($this->secret, 0, 32)).substr($this->secret, 32),
        'basic' => 'Basic '.base64_encode($this->secret),
    };
    if (is_array($header)) {
        $request = Request::create($this->url, 'GET');
        $request->headers->set('Authorization', $header);
        $response = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request));
    } else {
        $response = $this->withHeader('Authorization', $header)->getJson($this->url);
    }
    $response->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')->assertDontSee($this->secret);
    expect(Activity::where('event', 'documents.read')->count())->toBe(0)->and($this->credential->fresh()->last_used_at)->toBeNull();
})->with(['truncated', 'oversized', 'combined', 'duplicate', 'lowercase-selector', 'basic']);

test('review does not broaden binding even when sponsor owns the sibling entity', function () {
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $this->integration->workspace_id]);
    $original = LegalEntity::findOrFail($this->integration->legal_entity_id);
    $foreign = str_replace($original->public_id, $entity->public_id, $this->url);
    $this->getJson($foreign)->assertForbidden();
    $event = Activity::where('event', 'documents.read.denied')->firstOrFail();
    expect($event->properties['legal_entity_id'])->toBe($original->id)->and(json_encode($event->properties))->not->toContain($entity->public_id);
});

test('review denial audit also fails closed when logging silently refuses persistence', function () {
    activity()->disableLogging();
    try {
        $this->getJson(str_replace('/homologation/', '/production/', $this->url))->assertStatus(500);
        expect($this->credential->fresh()->last_used_at)->toBeNull();
    } finally {
        activity()->enableLogging();
    }
});

test('review blocked account deletion preserves the human session and integration evidence', function () {
    $user = User::findOrFail($this->integration->sponsor_user_id);
    $this->flushHeaders()->actingAs($user)->withSession([
        'auth.password_confirmed_at' => time(),
        (string) config('work_session.started_at_key') => time(),
    ])->from(route('settings.account'))->delete(route('settings.account.destroy'))->assertRedirect(route('settings.account'))->assertSessionHas('error');
    $this->assertAuthenticatedAs($user);
    expect($this->integration->fresh())->not->toBeNull()->and($this->credential->fresh())->not->toBeNull();
});

test('review OpenAPI security headers match successful and rejected runtime responses', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-read-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $responses = array_values($spec['paths'])[1]['get']['responses'];
    $response = $this->getJson($this->url)->assertOk();
    $response->assertHeader('Cache-Control', $responses['200']['headers']['Cache-Control']['schema']['const']);
    expect(Str::isUuid($response->headers->get('X-Request-ID')))->toBeTrue();
    $this->flushHeaders()->getJson($this->url)->assertUnauthorized()
        ->assertHeader('Cache-Control', $responses['401']['headers']['Cache-Control']['schema']['const'])
        ->assertHeader('WWW-Authenticate', $responses['401']['headers']['WWW-Authenticate']['schema']['const']);
});
