<?php

use App\AgtEnvironment;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\ExecutionContext;
use App\FiscalDocumentStatus;
use App\Jobs\PollAgtSubmissionStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function boundaryProfile(): RecurringInvoice
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);

    return RecurringInvoice::factory()->autoIssuing()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'establishment_id' => Establishment::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id])->id,
        'customer_id' => Customer::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id])->id,
        'created_by_user_id' => $user->id,
    ]);
}

function environmentDocument(): FiscalDocument
{
    $profile = boundaryProfile();

    return FiscalDocument::factory()->create(['workspace_id' => $profile->workspace_id, 'legal_entity_id' => $profile->legal_entity_id,
        'establishment_id' => $profile->establishment_id, 'created_by_user_id' => $profile->created_by_user_id]);
}

test('environment migration derives linked evidence and quarantines unknown without changing fiscal bytes', function () {
    $billingIndex = require database_path('migrations/2026_10_07_220542_add_billing_analytics_month_index.php');
    $billingIndex->down();
    $migration = require database_path('migrations/2026_10_07_111342_bind_fiscal_environment_identity.php');
    $migration->down();
    $series = FiscalSeries::withoutEvents(fn () => FiscalSeries::factory()->create());
    $attributes = FiscalDocument::factory()->issued()->make(['workspace_id' => $series->workspace_id, 'legal_entity_id' => $series->legal_entity_id,
        'establishment_id' => $series->establishment_id, 'agt_connection_id' => $series->agt_connection_id, 'fiscal_series_id' => $series->id,
        'document_jws' => 'immutable.signature', 'document_payload_sha256' => hash('sha256', 'frozen')])->getAttributes();
    unset($attributes['environment']);
    $attributes['public_id'] = (string) Str::ulid();
    $known = DB::table('fiscal_documents')->insertGetId($attributes);
    $attributes['public_id'] = (string) Str::ulid();
    $attributes['document_no'] = 'FT LEGACY/2';
    $attributes['agt_connection_id'] = null;
    $attributes['fiscal_series_id'] = null;
    $unknown = DB::table('fiscal_documents')->insertGetId($attributes);
    $before = DB::table('fiscal_documents')->where('id', $known)->first();
    $migration->up();
    $billingIndex->up();
    $after = DB::table('fiscal_documents')->where('id', $known)->first();
    expect($after->environment)->toBe('homologation');
    unset($after->environment);
    expect((array) $after)->toBe((array) $before)
        ->and(DB::table('fiscal_documents')->where('id', $unknown)->value('environment'))->toBe('unresolved');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('environment preflight rejects contradictory legacy connection evidence before any mutation', function () {
    $billingIndex = require database_path('migrations/2026_10_07_220542_add_billing_analytics_month_index.php');
    $billingIndex->down();
    $migration = require database_path('migrations/2026_10_07_111342_bind_fiscal_environment_identity.php');
    $migration->down();
    $series = FiscalSeries::withoutEvents(fn () => FiscalSeries::factory()->create());
    $production = AgtConnection::factory()->create(['workspace_id' => $series->workspace_id, 'legal_entity_id' => $series->legal_entity_id, 'environment' => AgtEnvironment::Production]);
    $attributes = FiscalDocument::factory()->make(['workspace_id' => $series->workspace_id, 'legal_entity_id' => $series->legal_entity_id,
        'establishment_id' => $series->establishment_id, 'agt_connection_id' => $production->id, 'fiscal_series_id' => $series->id])->getAttributes();
    unset($attributes['environment']);
    $attributes['public_id'] = (string) Str::ulid();
    $id = DB::table('fiscal_documents')->insertGetId($attributes);
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Conflicting environment evidence');
        expect(Schema::hasColumn('fiscal_documents', 'environment'))->toBeFalse();
    } finally {
        DB::table('fiscal_documents')->where('id', $id)->update(['agt_connection_id' => $series->agt_connection_id]);
        $migration->up();
        $billingIndex->up();
    }
});

test('durable connection and series identity cannot be reassigned', function () {
    $series = FiscalSeries::factory()->create();
    expect(fn () => $series->agtConnection->update(['environment' => AgtEnvironment::Production]))->toThrow(DomainException::class);
    expect(fn () => $series->update(['environment' => 'production']))->toThrow(DomainException::class);
});

test('document number uniqueness is per entity and environment and connection links must agree', function () {
    $document = environmentDocument();
    $document->update(['document_no' => 'FT TEST/1']);
    $production = $document->replicate();
    $production->environment = 'production';
    $production->save();
    expect(FiscalDocument::count())->toBe(2);
    expect(fn () => $document->replicate()->save())->toThrow(QueryException::class);
    $connection = AgtConnection::factory()->create(['workspace_id' => $document->workspace_id, 'legal_entity_id' => $document->legal_entity_id]);
    expect(fn () => DB::table('fiscal_documents')->where('id', $production->id)->update(['agt_connection_id' => $connection->id]))->toThrow(QueryException::class);
});

test('read capability is explicitly scoped independently of mutable browser workspace and records attribution', function () {
    $document = environmentDocument();
    $actor = User::findOrFail($document->created_by_user_id);
    Context::add('request_id', 'contract-read-correlation');
    $context = ExecutionContext::resolve($actor, $document->legalEntity, AgtEnvironment::Homologation, readOnly: true);
    $foreign = environmentDocument();
    $actor->update(['current_workspace_id' => $foreign->workspace_id]);
    Context::add('workspace_id', $foreign->workspace_id);
    $result = app(DocumentCapabilities::class)->read($context, $document->public_id);
    expect($result['public_id'])->toBe($document->public_id)->and($result)->not->toHaveKey('document_jws');
    $entry = Activity::where('event', 'documents.read')->latest('id')->firstOrFail();
    expect($entry->properties['workspace_id'])->toBe($document->workspace_id)
        ->and($entry->properties['request_id'])->toBe('contract-read-correlation')
        ->and($entry->properties['real_actor_id'])->toBe($actor->id);
    expect(fn () => app(DocumentCapabilities::class)->read($context, $foreign->public_id))->toThrow(ModelNotFoundException::class);
    $document->update(['environment' => 'unresolved']);
    expect(fn () => app(DocumentCapabilities::class)->read($context, $document->public_id))->toThrow(ModelNotFoundException::class);
});

test('capabilities revalidate membership after context construction and viewers cannot approve', function () {
    $profile = boundaryProfile();
    $context = ExecutionContext::resolve($profile->createdBy, $profile->legalEntity, AgtEnvironment::Homologation);
    WorkspaceMembership::where('user_id', $profile->created_by_user_id)->update(['role' => WorkspaceRole::Viewer]);
    expect(fn () => app(DocumentCapabilities::class)->approveRecurring($context, $profile->public_id, CarbonImmutable::now()->addDay()))->toThrow(HttpException::class);
    $read = ExecutionContext::resolve($profile->createdBy, $profile->legalEntity, AgtEnvironment::Homologation, readOnly: true);
    $read->authorize('documents.read');
    WorkspaceMembership::where('user_id', $profile->created_by_user_id)->update(['is_active' => false]);
    expect(fn () => $read->authorize('documents.read'))->toThrow(HttpException::class);
    expect(DB::table('recurring_approvals')->count())->toBe(0);
});

test('consequential capability scopes target and preserves correlation with durable approval identity', function () {
    $profile = boundaryProfile();
    Context::add('request_id', 'approval-correlation');
    $context = ExecutionContext::resolve($profile->createdBy, $profile->legalEntity, AgtEnvironment::Homologation);
    $foreign = boundaryProfile();
    expect(fn () => app(DocumentCapabilities::class)->approveRecurring($context, $foreign->public_id, CarbonImmutable::now()->addDay()))->toThrow(ModelNotFoundException::class);
    $id = app(DocumentCapabilities::class)->approveRecurring($context, $profile->public_id, CarbonImmutable::now()->addDay());
    $entry = Activity::where('event', 'approved')->latest('id')->firstOrFail();
    expect($entry->properties['approval_id'])->toBe($id)->and($entry->properties['request_id'])->toBe('approval-correlation');
    Context::add('impersonator_id', 123);
    expect(fn () => app(DocumentCapabilities::class)->approveRecurring($context, $profile->public_id, CarbonImmutable::now()->addDay()))->toThrow(HttpException::class);
});

test('read capabilities isolate environments without requiring production AGT enablement', function () {
    $document = environmentDocument();
    $context = ExecutionContext::resolve(User::findOrFail($document->created_by_user_id), $document->legalEntity, AgtEnvironment::Production, readOnly: true);
    expect(fn () => app(DocumentCapabilities::class)->read($context, $document->public_id))->toThrow(ModelNotFoundException::class);
    $document->update(['environment' => 'production']);
    expect(app(DocumentCapabilities::class)->read($context, $document->public_id)['environment'])->toBe('production');
    $before = $document->fresh()->getAttributes();
    $this->artisan('fiscal:environment-audit')->assertExitCode(0);
    expect($document->fresh()->getAttributes())->toBe($before);
});

test('capability read distinguishes immutable issue evidence from authoritative current AGT status', function () {
    $document = environmentDocument();
    $document->update(['status' => FiscalDocumentStatus::Valid]);
    AgtSubmission::factory()->create(['fiscal_document_id' => $document->id]);
    $context = ExecutionContext::resolve(User::findOrFail($document->created_by_user_id), $document->legalEntity, AgtEnvironment::Homologation, readOnly: true);
    $result = app(DocumentCapabilities::class)->read($context, $document->public_id);
    expect($result['issue_status'])->toBe('valid')->and($result['agt_status'])->toBe('pending')->and($result['agt_status_source'])->toBe('submission');
});

test('AGT jobs fail closed before outbound calls if legacy evidence is inconsistent', function (string $job) {
    Http::preventStrayRequests();
    $submission = AgtSubmission::factory()->create(['status' => $job === 'poll' ? AgtSubmissionStatus::Received : AgtSubmissionStatus::Pending, 'request_id' => 'known-request']);
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('fiscal_documents')->where('id', $submission->fiscal_document_id)->update(['environment' => 'unresolved']);
    try {
        $worker = $job === 'poll' ? new PollAgtSubmissionStatus($submission->id) : new SubmitAgtDocument($submission->id);
        expect(fn () => $worker->handle(app(AgtGateway::class)))->toThrow(DomainException::class);
        expect($submission->fresh()->attempt_count)->toBe(0);
        Http::assertNothingSent();
    } finally {
        DB::table('fiscal_documents')->where('id', $submission->fiscal_document_id)->update(['environment' => 'homologation']);
        DB::statement('PRAGMA defer_foreign_keys = OFF');
    }
})->with(['poll', 'submit']);
