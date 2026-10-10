<?php

use App\AgtEnvironment;
use App\AgtSubmissionStatus;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentListCommand;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\ExecutionContext;
use App\FiscalDocumentStatus;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\ImpersonationSession;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array{user: User, entity: LegalEntity, document: FiscalDocument} */
function readApiCompany(): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $document = FiscalDocument::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'created_by_user_id' => $user->id]);

    return compact('user', 'entity', 'document');
}

function readApiUrl(array $company, bool $detail = true, string $environment = 'homologation'): string
{
    $parameters = ['workspacePublicId' => $company['entity']->workspace->public_id, 'entityPublicId' => $company['entity']->public_id, 'environment' => $environment];
    if ($detail) {
        $parameters['documentPublicId'] = $company['document']->public_id;
    }

    return route($detail ? 'api.v1.documents.show' : 'api.v1.documents.index', $parameters);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

test('document API requires authenticated verified MFA session authority', function (string $condition) {
    $company = readApiCompany();
    if ($condition !== 'anonymous') {
        if ($condition === 'unverified') {
            $company['user']->forceFill(['email_verified_at' => null])->save();
        } elseif ($condition === 'no-mfa') {
            $company['user']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        } elseif ($condition === 'inactive') {
            WorkspaceMembership::where('user_id', $company['user']->id)->update(['is_active' => false]);
        }
        $this->actingAs($company['user']);
    }
    $response = $this->get(readApiUrl($company));
    $response->assertStatus($condition === 'anonymous' ? 401 : 403)->assertJsonPath('error.code', $condition === 'anonymous' ? 'AUTHENTICATION_REQUIRED' : 'FORBIDDEN');
    expect($response->headers->get('X-Request-ID'))->toBe($response->json('meta.request_id'));
    expect(Activity::where('event', 'documents.read')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['anonymous', 'unverified', 'no-mfa', 'inactive']);

test('every active role can use safe reads independently of browser workspace', function (WorkspaceRole $role) {
    $company = readApiCompany();
    $other = readApiCompany();
    WorkspaceMembership::where('user_id', $company['user']->id)->update(['role' => $role]);
    $company['user']->update(['current_workspace_id' => $other['entity']->workspace_id]);
    $this->actingAs($company['user']);
    $before = $company['document']->fresh()->getAttributes();
    $response = $this->getJson(readApiUrl($company))->assertSuccessful()
        ->assertJsonPath('data.public_id', $company['document']->public_id)
        ->assertJsonPath('meta.workspace_public_id', $company['entity']->workspace->public_id)
        ->assertJsonPath('meta.legal_entity_public_id', $company['entity']->public_id)
        ->assertJsonPath('meta.environment', 'homologation');
    expect(array_keys($response->json('data')))->toBe(['public_id', 'document_no', 'environment', 'revision', 'currency_code', 'gross_total_minor', 'document_type', 'issue_status', 'agt_status', 'agt_status_source']);
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $audit = Activity::where('event', 'documents.read')->latest('id')->firstOrFail();
    expect($audit->properties['request_id'])->toBe($response->json('meta.request_id'))
        ->and($audit->properties['workspace_id'])->toBe($company['entity']->workspace_id)
        ->and($audit->properties['legal_entity_id'])->toBe($company['entity']->id)
        ->and($audit->properties['environment'])->toBe('homologation')
        ->and($audit->properties['effective_actor_id'])->toBe($company['user']->id)
        ->and($company['document']->fresh()->getAttributes())->toBe($before);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with(WorkspaceRole::cases());

test('foreign and sibling entity document identifiers cannot escape the requested scope', function () {
    $company = readApiCompany();
    $foreign = readApiCompany();
    $sibling = LegalEntity::factory()->configured()->create(['workspace_id' => $company['entity']->workspace_id]);
    $siblingDocument = FiscalDocument::factory()->create(['workspace_id' => $sibling->workspace_id, 'legal_entity_id' => $sibling->id]);
    $this->actingAs($company['user']);
    foreach ([$foreign['document'], $siblingDocument] as $document) {
        $target = [...$company, 'document' => $document];
        $response = $this->getJson(readApiUrl($target))->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $audit = Activity::where('event', 'documents.read.denied')->latest('id')->firstOrFail();
        expect($audit->properties['request_id'])->toBe($response->json('meta.request_id'))
            ->and($audit->properties['legal_entity_id'])->toBe($company['entity']->id);
    }
    $this->getJson(readApiUrl($foreign))->assertForbidden();
    $wrongWorkspace = str_replace($company['entity']->workspace->public_id, $foreign['entity']->workspace->public_id, readApiUrl($company));
    $this->getJson($wrongWorkspace)->assertForbidden();
    $this->getJson(readApiUrl($company, false))->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
});

test('known environments are isolated and unresolved documents remain quarantined', function () {
    $company = readApiCompany();
    $production = $company['document']->replicate();
    $production->environment = 'production';
    $production->save();
    $unknown = $company['document']->replicate();
    $unknown->environment = 'unresolved';
    $unknown->save();
    $this->actingAs($company['user']);
    $this->getJson(readApiUrl($company, false))->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $company['document']->public_id);
    $this->getJson(readApiUrl($company, false, 'production'))->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $production->public_id);
    $this->getJson(readApiUrl($company, true, 'production'))->assertNotFound();
    $this->getJson(readApiUrl([...$company, 'document' => $unknown]))->assertNotFound();
    $this->getJson(readApiUrl($company, false, 'unresolved'))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    Http::assertNothingSent();
});

test('API filters and safe reads use the authoritative AGT projection with provenance', function () {
    $company = readApiCompany();
    $company['document']->update(['status' => FiscalDocumentStatus::Valid, 'document_no' => 'FT TEST/1']);
    $submission = AgtSubmission::factory()->create(['fiscal_document_id' => $company['document']->id, 'status' => AgtSubmissionStatus::Failed]);
    $this->actingAs($company['user']);
    $this->getJson(readApiUrl($company))->assertSuccessful()->assertJsonPath('data.issue_status', 'valid')
        ->assertJsonPath('data.agt_status', 'failed')->assertJsonPath('data.agt_status_source', 'submission');
    $this->getJson(readApiUrl($company, false).'?agt_status=valid')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->getJson(readApiUrl($company, false).'?agt_status=failed')->assertSuccessful()->assertJsonCount(1, 'data');
    $submission->update(['status' => AgtSubmissionStatus::Valid]);
    $this->getJson(readApiUrl($company, false).'?agt_status=valid')->assertSuccessful()->assertJsonCount(1, 'data');
    expect($company['document']->fresh()->status)->toBe(FiscalDocumentStatus::Valid);
});

test('list pagination is bounded ordered scoped and never combines currency amounts', function () {
    $company = readApiCompany();
    for ($i = 0; $i < 3; $i++) {
        $document = $company['document']->replicate();
        $document->currency_code = $i === 0 ? 'USD' : 'AOA';
        $document->save();
    }
    $this->actingAs($company['user']);
    $response = $this->getJson(readApiUrl($company, false).'?per_page=2&page=2')->assertSuccessful()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.page', 2)->assertJsonPath('meta.last_page', 2);
    expect($response->json('data.1.public_id'))->toBe($company['document']->public_id)
        ->and($response->json('data.0.currency_code'))->toBe('USD');
    $this->getJson(readApiUrl($company, false).'?page=3&per_page=2')->assertSuccessful()->assertJsonCount(0, 'data');
    expect(Activity::where('event', 'documents.list')->count())->toBe(2);
});

test('invalid or authority-shaped list input is rejected without executing a capability', function (string $query) {
    $company = readApiCompany();
    $this->actingAs($company['user']);
    $this->getJson(readApiUrl($company, false).'?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(Activity::where('event', 'documents.list')->count())->toBe(0);
})->with(['per_page=51', 'page=0', 'page=10001', 'agt_status=unknown', 'type=UNKNOWN', 'workspace_id=1', 'permissions[]=fiscal.issue', 'include=document_jws', 'per_page[]=20']);

test('typed commands and shared capabilities enforce validation and current authority independently of HTTP', function () {
    $company = readApiCompany();
    $context = ExecutionContext::resolve($company['user'], $company['entity'], AgtEnvironment::Homologation, readOnly: true);
    $capabilities = app(DocumentCapabilities::class);
    $command = DocumentReadCommand::fromPublicId($company['document']->public_id);
    expect($capabilities->readDocument($context, $command)['public_id'])->toBe($company['document']->public_id);
    expect(fn () => DocumentListCommand::fromInput(['per_page' => 100]))->toThrow(ValidationException::class);
    expect(fn () => DocumentReadCommand::fromPublicId('not-a-public-id'))->toThrow(ValidationException::class);
    WorkspaceMembership::where('user_id', $company['user']->id)->update(['is_active' => false]);
    expect(fn () => $capabilities->readDocument($context, $command))->toThrow(HttpException::class);
    expect(fn () => $capabilities->listDocuments($context, DocumentListCommand::fromInput([])))->toThrow(HttpException::class);
});

test('expired work sessions cannot access the API and response remains JSON', function () {
    $company = readApiCompany();
    $this->actingAs($company['user'])->withSession([(string) config('work_session.started_at_key') => now()->subDay()->timestamp]);
    $this->get(readApiUrl($company))->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    $this->assertGuest();
});

test('read attribution preserves real and effective actors under support impersonation', function () {
    $company = readApiCompany();
    $this->actingAs($company['user']);
    Context::add(['impersonator_id' => 123, 'impersonation_session' => 'approved-support-reference']);
    $this->getJson(readApiUrl($company))->assertSuccessful();
    $audit = Activity::where('event', 'documents.read')->latest('id')->firstOrFail();
    expect($audit->properties['real_actor_id'])->toBe(123)->and($audit->properties['effective_actor_id'])->toBe($company['user']->id)
        ->and($audit->properties['impersonation_session'])->toBe('approved-support-reference');
});

test('API remains JSON under Inertia headers and has no consequential routes', function () {
    $company = readApiCompany();
    $this->actingAs($company['user']);
    $this->get(readApiUrl($company), ['X-Inertia' => 'true', 'X-Inertia-Version' => 'old-version'])->assertSuccessful()->assertJsonPath('data.public_id', $company['document']->public_id);
    $this->postJson(readApiUrl($company, false))->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    $apiRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'));
    expect($apiRoutes)->toHaveCount(6);
    foreach ($apiRoutes as $route) {
        expect($route->methods())->toBe(['GET', 'HEAD']);
    }
});

test('rate limiting returns a safe correlated retry response without extra domain work', function () {
    $company = readApiCompany();
    $this->actingAs($company['user']);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson(readApiUrl($company))->assertSuccessful();
    }
    $response = $this->getJson(readApiUrl($company))->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('X-Request-ID'))->toBe($response->json('meta.request_id'))
        ->and(Activity::where('event', 'documents.read')->count())->toBe(60);
});

test('unknown API paths receive fresh correlation identifiers and sanitized JSON errors', function () {
    $first = $this->get('/api/v1/not-found')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $second = $this->get('/api/v1/not-found')->assertNotFound();
    expect($first->json('meta.request_id'))->not->toBe($second->json('meta.request_id'));
});

test('published OpenAPI documents exactly the bounded routes and serialized read fields', function () {
    $specification = json_decode(file_get_contents(base_path('docs/openapi-read-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($specification['openapi'])->toBe('3.1.0')->and($specification['paths'])->toHaveCount(6);
    foreach ($specification['paths'] as $path => $operations) {
        expect(array_keys($operations))->toBe(['get', 'head']);
        foreach ($operations['head']['responses'] as $response) {
            expect($response)->not->toHaveKey('content');
        }
        expect($operations['get']['responses'])->toHaveKeys(['405', '429']);
        expect(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => '/'.$route->uri() === $path))->toBeTrue();
    }
    $company = readApiCompany();
    $this->actingAs($company['user']);
    $response = $this->getJson(readApiUrl($company))->assertSuccessful();
    $expected = array_keys($specification['components']['schemas']['Document']['properties']);
    $actual = array_keys($response->json('data'));
    sort($expected);
    sort($actual);
    expect($actual)->toBe($expected);
});

test('review HEAD errors are bodyless and preserve session cookies', function () {
    $company = readApiCompany();
    $this->call('HEAD', readApiUrl($company))->assertUnauthorized()->assertContent('');
    $this->actingAs($company['user']);
    $response = $this->getJson(str_replace($company['document']->public_id, (string) Str::ulid(), readApiUrl($company)))->assertNotFound();
    expect(collect($response->headers->getCookies())->pluck('name'))->not->toBeEmpty();
});

test('review foreign and nonexistent workspace identifiers have indistinguishable denials', function () {
    $company = readApiCompany();
    $foreign = readApiCompany();
    $this->actingAs($company['user']);
    $this->getJson(readApiUrl($foreign))->assertForbidden();
    $this->getJson(str_replace($foreign['entity']->workspace->public_id, (string) Str::ulid(), readApiUrl($foreign)))->assertForbidden();
});

test('review API root errors hide debug exception details', function () {
    config(['app.debug' => true]);
    $response = $this->getJson('/api/v1')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    expect($response->json())->toHaveKeys(['error', 'meta'])->not->toHaveKeys(['exception', 'trace', 'file']);
});

test('review HEAD applies the same scope and validation rules as GET', function () {
    $company = readApiCompany();
    $foreign = readApiCompany();
    $this->actingAs($company['user']);
    foreach ([['url' => readApiUrl($company), 'status' => 200], ['url' => readApiUrl($foreign), 'status' => 403], ['url' => readApiUrl($company, false).'?per_page=51', 'status' => 422], ['url' => '/api/v1/unknown', 'status' => 404]] as $case) {
        $this->call('HEAD', $case['url'])->assertStatus($case['status'])->assertContent('')->assertHeader('X-Request-ID');
    }
});

test('review current verification and MFA are revalidated by direct capabilities', function (string $field) {
    $company = readApiCompany();
    $context = ExecutionContext::resolve($company['user'], $company['entity'], AgtEnvironment::Homologation, readOnly: true);
    $company['user']->forceFill([$field => null])->save();
    expect(fn () => app(DocumentCapabilities::class)->listDocuments($context, DocumentListCommand::fromInput([])))->toThrow(HttpException::class);
})->with(['email_verified_at', 'two_factor_confirmed_at']);

test('review authorized multiworkspace reads use the explicit scope', function () {
    $company = readApiCompany();
    $other = readApiCompany();
    WorkspaceMembership::factory()->create(['workspace_id' => $other['entity']->workspace_id, 'user_id' => $company['user']->id, 'role' => WorkspaceRole::Viewer, 'is_active' => true]);
    $this->actingAs($company['user']);
    $this->getJson(readApiUrl($other))->assertOk()->assertJsonPath('data.public_id', $other['document']->public_id);
    expect($company['user']->fresh()->current_workspace_id)->toBe($company['entity']->workspace_id);
});

test('review real support session attribution reaches the read audit', function () {
    $company = readApiCompany();
    $support = User::factory()->create();
    $session = ImpersonationSession::factory()->create(['impersonator_id' => $support->id, 'subject_id' => $company['user']->id, 'workspace_id' => $company['entity']->workspace_id]);
    $this->actingAs($company['user'])->withSession([(string) config('impersonation.session.record') => $session->id]);
    $this->getJson(readApiUrl($company))->assertOk();
    $audit = Activity::where('event', 'documents.read')->latest('id')->firstOrFail();
    expect($audit->properties['real_actor_id'])->toBe($support->id)->and($audit->properties['impersonation_session'])->toBe($session->public_id)
        ->and($session->fresh()->write_count)->toBe(0);
    $foreign = readApiCompany();
    $this->getJson(readApiUrl($foreign))->assertForbidden();
    $denial = Activity::where('event', 'documents.read.denied')->latest('id')->firstOrFail();
    expect($denial->properties['real_actor_id'])->toBe($support->id)
        ->and($denial->properties['effective_actor_id'])->toBe($company['user']->id)
        ->and($denial->properties->has('workspace_id'))->toBeFalse();
});

test('review document list query count remains constant as the page grows', function () {
    $company = readApiCompany();
    $context = ExecutionContext::resolve($company['user'], $company['entity'], AgtEnvironment::Homologation, readOnly: true);
    $countQueries = function () use ($context): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(DocumentCapabilities::class)->listDocuments($context, DocumentListCommand::fromInput(['per_page' => 50]));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $single = $countQueries();
    FiscalDocument::factory()->count(49)->create(['establishment_id' => $company['document']->establishment_id, 'workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id, 'created_by_user_id' => $company['user']->id]);
    expect($countQueries())->toBe($single);
});

test('review audit storage failures never reveal internal exception details', function () {
    $company = readApiCompany();
    $foreign = readApiCompany();
    $this->actingAs($company['user']);
    config(['app.debug' => true]);
    Activity::creating(fn () => throw new RuntimeException('private-audit-storage-detail'));
    try {
        $this->getJson(readApiUrl($foreign))->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR')->assertDontSee('private-audit-storage-detail');
    } finally {
        Activity::flushEventListeners();
    }
});

test('v1 raw valid remains the exact legacy wire summary while unknown evidence denies receipt authority', function () {
    $company = readApiCompany();
    $company['document']->update(['status' => FiscalDocumentStatus::Issued]);
    $submission = AgtSubmission::factory()->for($company['document'], 'fiscalDocument')->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    $this->actingAs($company['user']);
    $response = $this->getJson(readApiUrl($company))->assertSuccessful()
        ->assertJsonPath('data.agt_status', 'valid')->assertJsonPath('data.agt_status_source', 'submission');
    expect(array_keys($response->json('data')))->toBe(['public_id', 'document_no', 'environment', 'revision', 'currency_code', 'gross_total_minor', 'document_type', 'issue_status', 'agt_status', 'agt_status_source'])
        ->and(CurrentAgtState::status($submission->fiscalDocument)->value)->toBe('unknown')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
    $this->getJson(readApiUrl($company, false).'?agt_status=valid')->assertSuccessful()->assertJsonCount(1, 'data');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
