<?php

use App\AgtEnvironment;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\Documents\AgtObservationReducer;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\Documents\QualifiedAgtStatusRead;
use App\Fiscal\ExecutionContext;
use App\Fiscal\IntegrationReadContext;
use App\Http\Resources\QualifiedAgtStatusResource;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

/** @return array<string, mixed> */
function qualifiedFixture(array $scopes = ['documents:agt-status:read'], string $environment = 'production', bool $issued = true): array
{
    $integration = Integration::factory()->create(['environment' => $environment]);
    $selector = (string) Str::ulid();
    $secret = 'fcr1.'.$selector.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $credential = IntegrationCredential::factory()->create(['integration_id' => $integration->id, 'public_id' => $selector, 'secret_hash' => hash('sha256', $secret)]);
    foreach ($scopes as $scope) {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
    }
    $factory = FiscalDocument::factory();
    if ($issued) {
        $factory = $factory->issued();
    }
    $document = $factory->create(['environment' => $environment, 'workspace_id' => $integration->workspace_id, 'legal_entity_id' => $integration->legal_entity_id]);
    $entity = LegalEntity::findOrFail($integration->legal_entity_id);
    $parameters = ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => $environment, 'documentPublicId' => $document->public_id];
    $url = route('integrations.v2.documents.agt-status.show', $parameters);
    $v1 = route('integrations.v1.documents.show', $parameters);

    return compact('integration', 'credential', 'secret', 'document', 'entity', 'parameters', 'url', 'v1');
}

/** @return array<string, mixed> */
function qualifiedState(string $reported = 'valid', string $sync = 'idle'): array
{
    $time = CarbonImmutable::now('UTC')->subMinute()->startOfSecond()->toIso8601String();
    $state = [...AgtObservationReducer::empty(), 'classification' => 'authoritative', 'knowledge' => 'known', 'reported_state' => $reported,
        'sync' => $sync, 'observation_id' => 1, 'observed_at' => $time, 'last_successful_sync_at' => $time,
        'reason' => match ($reported) {
            'valid' => 'validation_reported', 'invalid' => 'invalidity_reported', 'processing' => 'processing_reported', default => 'processing_cancelled'
        }];
    $state['operational_status'] = AgtObservationReducer::operationalStatus($state);

    return $state;
}

function qualifiedSubmission(array $fixture, ?array $state, int $version = 1, string $status = 'valid'): AgtSubmission
{
    $submission = AgtSubmission::factory()->create(['fiscal_document_id' => $fixture['document']->id, 'environment' => $fixture['integration']->environment,
        'status' => ($state['operational_status'] ?? $status) === 'unknown' ? 'received' : ($state['operational_status'] ?? $status),
        'agt_connection_id' => AgtConnection::factory()->create(['workspace_id' => $fixture['integration']->workspace_id, 'legal_entity_id' => $fixture['integration']->legal_entity_id, 'environment' => $fixture['integration']->environment])->id,
        'qualified_projection' => null, 'safe_message' => 'POISON secret <script> upstream']);
    if ($state !== null && $state['observation_id'] !== null) {
        $outcome = [...array_intersect_key($state, array_flip(['version', 'classification', 'knowledge', 'reported_state', 'sync', 'delivery_state', 'reason'])), 'successful_sync' => true, 'eligible_generation' => true];
        $entry = AgtSubmissionExecution::append($submission, (string) Str::uuid(), 0, 'legacy_import', $outcome, CarbonImmutable::now('UTC'), CarbonImmutable::parse($state['observed_at']));
        $state['observation_id'] = $entry->id;
    }
    $submission->update(['qualified_projection' => $state, 'reducer_version' => $version]);

    return $submission;
}

/** Validate the JSON Schema keywords used by the approved closed OpenAPI schemas, including conditional constraints. */
function qualifiedSchemaValid(mixed $value, array $schema, array $spec): bool
{
    if (isset($schema['$ref'])) {
        $schema = data_get($spec, str_replace('/', '.', substr($schema['$ref'], 2)));
    }
    foreach ($schema['allOf'] ?? [] as $part) {
        if (! qualifiedSchemaValid($value, $part, $spec)) {
            return false;
        }
    }
    if (isset($schema['if'])) {
        $branch = qualifiedSchemaValid($value, $schema['if'], $spec) ? ($schema['then'] ?? []) : ($schema['else'] ?? []);
        if (! qualifiedSchemaValid($value, $branch, $spec)) {
            return false;
        }
    }
    if (isset($schema['type'])) {
        $actual = match (true) {
            $value === null => 'null', is_bool($value) => 'boolean', is_int($value) => 'integer', is_string($value) => 'string', is_array($value) => 'object', default => 'unsupported'
        };
        if (! in_array($actual, (array) $schema['type'], true)) {
            return false;
        }
    }
    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        return false;
    }
    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        return false;
    }
    if (is_string($value)) {
        if (isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~D', $value) !== 1) {
            return false;
        }
        if (($schema['format'] ?? null) === 'uuid' && ! Str::isUuid($value)) {
            return false;
        }
        if (($schema['format'] ?? null) === 'date-time') {
            try {
                CarbonImmutable::parse($value);
            } catch (Throwable) {
                return false;
            }
        }
    }
    if (is_array($value)) {
        if (array_diff($schema['required'] ?? [], array_keys($value)) !== []) {
            return false;
        }
        if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) !== []) {
            return false;
        }
        foreach ($schema['properties'] ?? [] as $key => $part) {
            if (array_key_exists($key, $value) && ! qualifiedSchemaValid($value[$key], $part, $spec)) {
                return false;
            }
        }
    }

    return true;
}

test('qualified V2 exact state matrix conforms to closed schema and semantic priority', function (string $case, string $knowledge, ?string $reported, string $code, string $provenance, bool $reconcile) {
    $f = qualifiedFixture(issued: $case !== 'draft');
    $state = match ($case) {
        'valid', 'stale', 'future', 'future-success', 'unsupported', 'extra', 'bad-enum', 'bad-reason', 'mismatch' => qualifiedState(),
        'invalid' => qualifiedState('invalid'), 'processing' => qualifiedState('processing', 'pending'), 'cancelled' => qualifiedState('processing_cancelled'),
        'refresh' => qualifiedState('valid', 'pending'), 'failed' => qualifiedState('valid', 'failed'),
        'ack' => [...AgtObservationReducer::empty(), 'classification' => 'partial', 'delivery_state' => 'acknowledged', 'reason' => 'delivery_acknowledged', 'operational_status' => 'received'],
        'partial' => [...AgtObservationReducer::empty(), 'classification' => 'partial', 'knowledge' => 'legacy_unverified', 'reason' => 'legacy_unverified', 'operational_status' => 'unknown'],
        'partial-empty', 'partial-failed' => [...AgtObservationReducer::empty(), 'classification' => 'partial', 'sync' => $case === 'partial-failed' ? 'failed' : 'idle', 'delivery_state' => $case === 'partial-failed' ? 'failed' : 'unknown', 'operational_status' => 'unknown'],
        'bare-v' => null,
        'queued' => AgtObservationReducer::queued(),
        'conflict' => [...qualifiedState(), 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reported_state' => null, 'operational_status' => 'unknown', 'reason' => 'evidence_conflict'],
        'result7', 'unknown-after-v' => [...qualifiedState(), 'classification' => 'unknown', 'knowledge' => 'unknown_response', 'reported_state' => null, 'operational_status' => 'unknown', 'sync' => $case === 'result7' ? 'pending' : 'idle', 'reason' => 'unknown_response'],
        default => null,
    };
    if ($case === 'stale') {
        $state['observed_at'] = $state['last_successful_sync_at'] = CarbonImmutable::now('UTC')->subHour()->startOfSecond()->toIso8601String();
    }
    if ($case === 'future') {
        $state['observed_at'] = CarbonImmutable::now('UTC')->addHour()->startOfSecond()->toIso8601String();
    }
    if ($case === 'future-success') {
        $state['last_successful_sync_at'] = CarbonImmutable::now('UTC')->addHour()->startOfSecond()->toIso8601String();
    }
    if ($case === 'extra') {
        $state['secret'] = 'POISON';
    }
    if ($case === 'bad-enum') {
        $state['knowledge'] = 'POISON';
    }
    if ($case === 'bad-reason') {
        $state['reason'] = '<script>POISON</script>';
    }
    if (! in_array($case, ['draft', 'missing'], true)) {
        $submission = qualifiedSubmission($f, $state, $case === 'unsupported' ? 99 : 1);
        if ($case === 'mismatch') {
            $submission->update(['status' => 'received']);
        }
    }
    $before = [];
    foreach (['fiscal_documents', 'agt_submissions', 'agt_submission_attempts', 'agt_submission_observations'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $response = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'])->assertOk()->assertDontSee('POISON');
    $data = $response->json('data');
    expect(array_keys($response->json()))->toBe(['data', 'meta'])->and(array_keys($data))->toBe(QualifiedAgtStatusRead::FIELDS)
        ->and($data['knowledge'])->toBe($knowledge)->and($data['reported_state'])->toBe($reported)->and($data['explanation_code'])->toBe($code)
        ->and($data['provenance'])->toBe($provenance)->and($data['reconciliation_required'])->toBe($reconcile);
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-qualified-agt-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(qualifiedSchemaValid($response->json(), $spec['components']['schemas']['QualifiedAgtStatusResponse'], $spec))->toBeTrue();
    foreach ($before as $table => $bytes) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($bytes);
    }
    if ($knowledge !== 'known') {
        expect($data['observed_at'])->toBeNull()->and($data['freshness'])->toBe($knowledge === 'not_applicable' ? 'not_applicable' : 'unverified');
    }
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with([
    ['draft', 'not_applicable', null, 'not_submitted', 'not_applicable', false], ['missing', 'unknown', null, 'state_unavailable', 'unverified', true],
    ['bare-v', 'unknown', null, 'state_unavailable', 'unverified', true],
    ['partial-empty', 'insufficient_evidence', null, 'legacy_unverified', 'unverified', true],
    ['partial-failed', 'insufficient_evidence', null, 'legacy_unverified', 'unverified', true], ['partial', 'insufficient_evidence', null, 'legacy_unverified', 'unverified', true],
    ['valid', 'known', 'valid', 'validation_reported', 'agt_observation', false], ['invalid', 'known', 'invalid', 'invalidity_reported', 'agt_observation', false],
    ['processing', 'known', 'processing', 'refresh_pending', 'agt_observation', false], ['cancelled', 'known', 'processing_cancelled', 'processing_cancelled', 'agt_observation', false],
    ['ack', 'unknown', null, 'delivery_acknowledged', 'workflow_only', false], ['queued', 'unknown', null, 'refresh_pending', 'workflow_only', false],
    ['refresh', 'unknown', null, 'refresh_pending', 'unverified', false], ['failed', 'unknown', null, 'sync_failed', 'unverified', true],
    ['conflict', 'conflicting_evidence', null, 'evidence_conflict', 'unverified', true], ['result7', 'unknown', null, 'refresh_pending', 'unverified', false],
    ['unknown-after-v', 'unknown', null, 'unknown_response', 'unverified', true], ['stale', 'known', 'valid', 'stale_observation', 'agt_observation', false],
    ['future', 'unknown', null, 'state_unavailable', 'unverified', true], ['future-success', 'unknown', null, 'state_unavailable', 'unverified', true],
    ['mismatch', 'insufficient_evidence', null, 'legacy_unverified', 'unverified', true],
]);

test('qualified freshness exact boundary and poisoned resource additions', function (int $seconds, string $freshness) {
    $f = qualifiedFixture();
    $time = CarbonImmutable::parse('2026-10-07T10:00:00+00:00');
    $state = qualifiedState();
    $state['observed_at'] = $state['last_successful_sync_at'] = $time->toIso8601String();
    qualifiedSubmission($f, $state);
    $document = app(QualifiedAgtStatusRead::class)->snapshot(IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()), DocumentReadCommand::fromPublicId($f['document']->public_id));
    $document->setAttribute('secret', 'POISON');
    $data = app(QualifiedAgtStatusRead::class)->present($document, $time->addSeconds($seconds));
    expect($data['freshness'])->toBe($freshness)->and($data['reported_state'])->toBe('valid')->and($data['as_of'])->toBe($time->addSeconds($seconds)->toIso8601String());
    expect(array_keys((new QualifiedAgtStatusResource([...$data, 'receipt_eligible' => true, 'secret' => 'POISON']))->resolve()))->toBe(QualifiedAgtStatusRead::FIELDS);
})->with([[899, 'recent_observation'], [900, 'stale'], [90000, 'stale']]);

test('all sixteen scope subsets grant only their independent routes', function (int $mask) {
    $all = ['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read'];
    $scopes = array_values(array_filter($all, fn (string $scope, int $i) => ($mask & (1 << $i)) !== 0, ARRAY_FILTER_USE_BOTH));
    $f = qualifiedFixture($scopes);
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    foreach (['documents:read' => $f['v1'], 'documents:agt-status:read' => $f['url'], 'customers:read' => route('integrations.v1.customers.index', $f['parameters']), 'catalogue:read' => route('integrations.v1.catalogue.index', $f['parameters'])] as $scope => $url) {
        // List routes deliberately reject extraneous query input; omit the document parameter.
        $url = strtok($url, '?');
        $this->getJson($url)->assertStatus(in_array($scope, $scopes, true) ? 200 : 403);
    }
})->with(range(0, 15));

test('qualified direct capability revalidates captured authority on every call', function (string $change, int $status) {
    $f = qualifiedFixture();
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id));
    match ($change) {
        'parent' => DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete(),
        'credential' => DB::table('integration_credential_scopes')->where('credential_id', $f['credential']->id)->delete(),
        'demote' => WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['role' => 'viewer']),
        'inactive' => WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['is_active' => false]),
        'membership' => WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->delete(),
        'verification' => User::where('id', $f['integration']->sponsor_user_id)->update(['email_verified_at' => null]),
        'mfa' => User::where('id', $f['integration']->sponsor_user_id)->update(['two_factor_secret' => null]),
        'integration' => $f['integration']->update(['revoked_at' => now()]),
        'revoked' => $f['credential']->update(['revoked_at' => now()]),
        'expired' => $this->travelTo($f['credential']->expires_at->addSecond()),
    };
    try {
        app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id));
        $this->fail('Authority was reused');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe($status);
    }
})->with([['parent', 403], ['credential', 403], ['demote', 403], ['inactive', 403], ['membership', 403], ['verification', 403], ['mfa', 403], ['integration', 401], ['revoked', 401], ['expired', 401]]);

test('qualified context denial performs no target SQL and never echoes selectors', function (string $selector) {
    $f = qualifiedFixture();
    $parameters = $f['parameters'];
    $parameters[$selector] = $selector === 'environment' ? 'homologation' : strtolower((string) Str::ulid());
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(route('integrations.v2.documents.agt-status.show', $parameters))->assertForbidden()->assertDontSee($parameters[$selector]);
    expect(implode(' ', $queries))->not->toContain('fiscal_documents');
    $audit = Activity::where('event', 'documents.agt-status.read.denied')->firstOrFail();
    expect($audit->properties['capability_version'])->toBe(2)->and($audit->properties['method'])->toBe('GET')->and(json_encode($audit->properties))->not->toContain($parameters[$selector]);
})->with(['workspacePublicId', 'entityPublicId', 'environment']);

test('qualified bound target not found classes are indistinguishable', function (string $case) {
    $f = qualifiedFixture();
    $foreign = match ($case) {
        'tenant' => FiscalDocument::factory()->create(),
        'entity' => FiscalDocument::factory()->create(['legal_entity_id' => LegalEntity::factory()->configured()->create(['workspace_id' => $f['integration']->workspace_id])->id]),
        'environment' => FiscalDocument::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $f['integration']->legal_entity_id, 'environment' => 'homologation', 'establishment_id' => $f['document']->establishment_id]),
        'unresolved' => FiscalDocument::factory()->create(['workspace_id' => $f['integration']->workspace_id, 'legal_entity_id' => $f['integration']->legal_entity_id, 'environment' => 'unresolved', 'establishment_id' => $f['document']->establishment_id]),
        default => null,
    };
    $p = [...$f['parameters'], 'documentPublicId' => $foreign?->public_id ?? strtolower((string) Str::ulid())];
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson(route('integrations.v2.documents.agt-status.show', $p))->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND')->assertDontSee($p['documentPublicId']);
})->with(['tenant', 'entity', 'environment', 'unresolved', 'missing']);

test('qualified GET HEAD audit parity no conditional bypass and no data leakage', function (string $method) {
    $f = qualifiedFixture();
    qualifiedSubmission($f, qualifiedState());
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$f['secret'], 'If-None-Match' => '*', 'If-Modified-Since' => 'Wed, 07 Oct 2099 10:00:00 GMT', 'X-Client-Request-ID' => 'client-123'])->json($method, $f['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->has('ETag'))->toBeFalse()->and($response->headers->has('Last-Modified'))->toBeFalse();
    if ($method === 'HEAD') {
        expect($response->getContent())->toBe('');
    }
    $audit = Activity::where('event', 'documents.agt-status.read')->firstOrFail();
    expect($audit->properties['capability_version'])->toBe(2)->and($audit->properties['method'])->toBe($method)->and($audit->properties['actor_kind'])->toBe('integration')
        ->and($audit->properties['authority_user_id'])->toBe($f['integration']->sponsor_user_id)->and($audit->properties['request_id'])->toBe($response->headers->get('X-Request-ID'))
        ->and($audit->properties['operation_id'])->not->toBe($audit->properties['request_id'])->and($audit->properties['client_request_id'])->toBe('client-123')
        ->and(json_encode($audit->properties))->not->toContain('qualified_projection', 'reported_state', 'POISON', $f['secret'])
        ->and($f['credential']->fresh()->last_used_at)->not->toBeNull();
})->with(['GET', 'HEAD']);

test('qualified boundary sanitizes every unsupported method including automatic OPTIONS', function (string $method) {
    $f = qualifiedFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->json($method, $f['url'])->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    expect(Activity::where('event', 'documents.agt-status.read.denied')->firstOrFail()->properties['method'])->toBe($method);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

test('qualified malformed input never queries target', function (string $input) {
    $f = qualifiedFixture();
    $url = $f['url'];
    $body = [];
    if ($input === 'path') {
        $url = str_replace($f['document']->public_id, 'bad-selector', $url);
    } elseif ($input === 'body') {
        $body = ['refresh' => true];
    } elseif ($input === 'delegation') {
        $this->withHeader('X-Agent-ID', 'POISON');
    } elseif ($input === 'correlation') {
        $this->withHeader('X-Client-Request-ID', '<script>');
    } else {
        $url .= '?'.$input.'=POISON';
    }
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    if ($input === 'body') {
        $this->json('GET', $url, $body)->assertUnprocessable();
    } else {
        $this->getJson($url)->assertUnprocessable()->assertDontSee('POISON');
    }
    expect(implode(' ', $queries))->not->toContain('fiscal_documents');
})->with(['path', 'body', 'delegation', 'correlation', 'as_of', 'fields', 'refresh', 'include', 'search', 'page']);

test('qualified audit persistence failures deny before last-use', function (string $failure, string $method) {
    $f = qualifiedFixture();
    if ($failure === 'buffer') {
        config(['activitylog.buffer.enabled' => true]);
    } elseif ($failure === 'throw') {
        Activity::creating(fn () => throw new RuntimeException('POISON secret exception'));
    } else {
        activity()->disableLogging();
    }
    try {
        $r = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->json($method, $f['url'])->assertStatus(503);
        if ($method === 'GET') {
            $r->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertDontSee('POISON');
        } else {
            expect($r->getContent())->toBe('');
        }
        expect($f['credential']->fresh()->last_used_at)->toBeNull();
    } finally {
        activity()->enableLogging();
        Activity::flushEventListeners();
    }
})->with(['buffer', 'throw', 'silent'])->with(['GET', 'HEAD']);

test('qualified denial audit failure and disabled gate preserve HEAD behavior', function () {
    $f = qualifiedFixture([]);
    activity()->disableLogging();
    try {
        $this->withHeader('Authorization', 'Bearer '.$f['secret'])->json('HEAD', $f['url'])->assertStatus(500);
    } finally {
        activity()->enableLogging();
    }
    config(['integrations.enabled' => false]);
    $this->getJson($f['url'])->assertNotFound();
    expect($this->json('HEAD', $f['url'])->assertNotFound()->getContent())->toBe('');
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
});

test('qualified status homologation remains independent of master production rule and browser state', function () {
    $f = qualifiedFixture(['documents:agt-status:read', 'customers:read', 'catalogue:read'], 'homologation');
    $this->actingAs(User::factory()->withWorkspace()->create());
    Context::add(['impersonator_id' => 999, 'workspace_id' => 999]);
    $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'])->assertOk();
    foreach (['customers.index', 'catalogue.index'] as $name) {
        $this->getJson(strtok(route('integrations.v1.'.$name, $f['parameters']), '?'))->assertForbidden();
    }
    $this->flushHeaders()->getJson($f['url'])->assertUnauthorized();
});

test('qualified V2 leaves V1 legacy valid summary unchanged and never grants receipt authority', function () {
    $f = qualifiedFixture(['documents:read', 'documents:agt-status:read']);
    qualifiedSubmission($f, [...AgtObservationReducer::empty(), 'classification' => 'partial', 'knowledge' => 'legacy_unverified', 'reason' => 'legacy_unverified', 'operational_status' => 'unknown']);
    DB::table('agt_submissions')->where('fiscal_document_id', $f['document']->id)->update(['status' => 'valid']);
    $v1 = $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['v1'])->assertOk()->assertJsonPath('data.agt_status', 'valid')->json('data');
    $this->getJson($f['url'])->assertOk()->assertJsonPath('data.knowledge', 'insufficient_evidence')->assertJsonPath('data.reported_state', null);
    expect($this->getJson($f['v1'])->json('data'))->toBe($v1)->and(count($v1))->toBe(10)->and(CurrentAgtState::validated($f['document']))->toBeFalse();
});

test('qualified snapshot is single primary bounded statement with no historical evidence query', function () {
    $f = qualifiedFixture();
    qualifiedSubmission($f, qualifiedState());
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id));
    $snapshot = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'fiscal_documents')));
    expect($snapshot)->toHaveCount(1)->and($snapshot[0])->toContain('left join', 'agt_submissions')->not->toContain('*', 'request_body')
        ->and(implode(' ', $queries))->not->toContain('agt_submission_observations', 'agt_submission_attempts');
});

test('qualified OpenAPI exact parity and V1 annotation-only compatibility', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-qualified-agt-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    $design = file_get_contents(base_path('docs/phase-4c-qualified-agt-read-contract.md'));
    preg_match('/```json\n(.*?)\n```/s', $design, $match);
    expect($spec)->toBe(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR));
    $path = array_values($spec['paths'])[0];
    expect(array_keys($path))->toBe(['parameters', 'get', 'head'])->and($path['get']['x-required-scopes'])->toBe(['documents:agt-status:read']);
    foreach ($path['head']['responses'] as $response) {
        if (isset($response['$ref'])) {
            $response = data_get($spec, str_replace('/', '.', substr($response['$ref'], 2)));
        }
        expect($response)->not->toHaveKey('content');
    }
    $example = $path['get']['responses']['200']['content']['application/json']['example'];
    expect(qualifiedSchemaValid($example, $spec['components']['schemas']['QualifiedAgtStatusResponse'], $spec))->toBeTrue();
    $example['data']['receipt_eligible'] = true;
    expect(qualifiedSchemaValid($example, $spec['components']['schemas']['QualifiedAgtStatusResponse'], $spec))->toBeFalse();
    unset($example['data']['receipt_eligible']);
    $example['data']['knowledge'] = 'unknown';
    expect(qualifiedSchemaValid($example, $spec['components']['schemas']['QualifiedAgtStatusResponse'], $spec))->toBeFalse();
});

test('qualified future and malformed in-memory projections degrade without weakening database guards', function (string $poison) {
    $f = qualifiedFixture();
    $submission = qualifiedSubmission($f, qualifiedState());
    $document = app(QualifiedAgtStatusRead::class)->snapshot(IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()), DocumentReadCommand::fromPublicId($f['document']->public_id));
    $live = $document->submissions->first();
    $state = $live->qualified_projection;
    if ($poison === 'version') {
        $live->reducer_version = 99;
    } elseif ($poison === 'extra') {
        $state['secret'] = 'POISON';
    } elseif ($poison === 'reason') {
        $state['reason'] = 'POISON';
    } elseif ($poison === 'knowledge') {
        $state['knowledge'] = 'future';
    } else {
        $state = null;
    }
    $live->qualified_projection = $state;
    $data = app(QualifiedAgtStatusRead::class)->present($document, CarbonImmutable::now('UTC'));
    expect($data['explanation_code'])->toBe('state_unavailable')->and($data['knowledge'])->toBe('unknown')->and($data['reported_state'])->toBeNull()->and(json_encode($data))->not->toContain('POISON');
    expect($submission->fresh()->qualified_projection['knowledge'])->toBe('known');
})->with(['version', 'extra', 'reason', 'knowledge', 'null']);

test('qualified bearer failures remain indistinguishable for GET HEAD and do not count successful use', function (string $failure, string $method) {
    $f = qualifiedFixture();
    $bearer = $f['secret'];
    if ($failure === 'revoked') {
        $f['credential']->update(['revoked_at' => now()]);
    } elseif ($failure === 'expired') {
        $this->travelTo($f['credential']->expires_at->addSecond());
    } elseif ($failure === 'unknown') {
        $bearer = str_replace($f['credential']->public_id, (string) Str::ulid(), $bearer);
    } elseif ($failure === 'malformed') {
        $bearer = 'POISON';
    }
    $response = $this->withHeader('Authorization', 'Bearer '.$bearer)->json($method, $f['url'])->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')->assertHeader('Cache-Control', 'no-store, private');
    if ($method === 'HEAD') {
        expect($response->getContent())->toBe('');
    } else {
        $response->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED')->assertDontSee('POISON');
    }
    expect($f['credential']->fresh()->last_used_at)->toBeNull();
})->with(['unknown', 'malformed', 'revoked', 'expired'])->with(['GET', 'HEAD']);

test('qualified error OpenAPI code association and headers match all runtime classes', function () {
    $f = qualifiedFixture();
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    $responses = [200 => $this->getJson($f['url']), 404 => $this->getJson($f['url'].'/not-a-route'), 405 => $this->json('OPTIONS', $f['url']), 422 => $this->getJson($f['url'].'?include=secret')];
    $other = str_replace('/production/', '/homologation/', $f['url']);
    $responses[403] = $this->getJson($other);
    $responses[401] = $this->flushHeaders()->getJson($f['url']);
    $this->withHeader('Authorization', 'Bearer '.$f['secret']);
    activity()->disableLogging();
    try {
        $responses[503] = $this->getJson($f['url']);
        $responses[500] = $this->getJson($other);
    } finally {
        activity()->enableLogging();
    }
    $key = 'external-read:'.hash('sha256', 'integration:'.$f['integration']->id).':'.intdiv(time(), 60);
    Cache::store(config('integrations.cache_store'))->put($key, 60, 61);
    $responses[429] = $this->getJson($f['url']);
    $spec = json_decode(file_get_contents(base_path('docs/openapi-external-qualified-agt-v2.json')), true, flags: JSON_THROW_ON_ERROR);
    $operation = array_values($spec['paths'])[0]['get'];
    foreach ($responses as $status => $response) {
        $response->assertStatus($status);
        $definition = $operation['responses'][$status];
        if (isset($definition['$ref'])) {
            $definition = data_get($spec, str_replace('/', '.', substr($definition['$ref'], 2)));
        }
        expect(qualifiedSchemaValid($response->json(), $definition['content']['application/json']['schema'], $spec))->toBeTrue();
        foreach ($definition['headers'] as $header => $definitionHeader) {
            if (isset($definitionHeader['$ref'])) {
                $definitionHeader = data_get($spec, str_replace('/', '.', substr($definitionHeader['$ref'], 2)));
            }
            $value = $response->headers->get($header);
            if (($definitionHeader['schema']['type'] ?? 'string') === 'integer') {
                $value = (int) $value;
                expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
            }
            expect(qualifiedSchemaValid($value, $definitionHeader['schema'], $spec))->toBeTrue();
        }
    }
});

test('qualified internal human read permission remains live and supports only existing read attribution', function () {
    $f = qualifiedFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    Context::add(['impersonator_id' => 777, 'impersonation_session' => 'support-session']);
    $context = ExecutionContext::resolve($user, $f['entity'], AgtEnvironment::Production, readOnly: true);
    app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id));
    $audit = Activity::where('event', 'documents.agt-status.read')->firstOrFail();
    expect($audit->properties['real_actor_id'])->toBe(777)->and($audit->properties['effective_actor_id'])->toBe($user->id);
    WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['is_active' => false]);
    expect(fn () => app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($f['document']->public_id)))->toThrow(HttpException::class);
});

test('qualified reads accept only the established empty body encodings and reject form and files', function (string $body) {
    $f = qualifiedFixture();
    $request = Request::create($f['url'], 'GET', content: $body);
    $request->headers->set('Authorization', 'Bearer '.$f['secret']);
    $response = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request));
    $response->assertStatus(in_array($body, ['', '{}', '[]'], true) ? 200 : 422);
})->with(['', '{}', '[]', '{"refresh":true}', 'POISON']);

test('qualified serialization validation fails before audit and last use', function () {
    $f = qualifiedFixture();
    FiscalDocument::retrieved(function (FiscalDocument $document): void {
        $document->public_id = 'invalid POISON identifier';
    });
    try {
        $this->withHeader('Authorization', 'Bearer '.$f['secret'])->getJson($f['url'])->assertStatus(503)->assertDontSee('POISON');
        expect(Activity::where('event', 'documents.agt-status.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
    } finally {
        FiscalDocument::flushEventListeners();
    }
});

test('qualified refuses GET form fields and file uploads before lookup', function (string $kind) {
    $f = qualifiedFixture();
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries[] = $q->sql;
    });
    $request = Request::create($f['url'], 'GET');
    $request->headers->set('Authorization', 'Bearer '.$f['secret']);
    if ($kind === 'form') {
        $request->request->replace(['refresh' => 'true']);
    } else {
        $request->files->set('payload', UploadedFile::fake()->create('evidence.txt', 1));
    }
    TestResponse::fromBaseResponse(app(Kernel::class)->handle($request))->assertUnprocessable();
    expect(implode(' ', $queries))->not->toContain('fiscal_documents');
})->with(['form', 'file']);
