<?php

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Data\AgtDocumentStatusResult;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Documents\AgtEvidence;
use App\Fiscal\Documents\AgtObservationReducer;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\Documents\AgtStatusPresentation;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\Documents\CurrentAgtState;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionAttempt;
use App\Models\AgtSubmissionObservation;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Notifications\DocumentAcceptedByAgt;
use App\Notifications\NotificationFeed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function observationResult(AgtSubmission $submission, string $status = 'V'): AgtInvoiceStatusResult
{
    $request = json_encode(['requestID' => $submission->request_id, 'schemaVersion' => '2.0', 'taxRegistrationNumber' => $submission->legalEntity->tax_identification_number], JSON_THROW_ON_ERROR);
    $code = $status === 'I' ? '2' : '0';
    $response = json_encode(['resultCode' => $code, 'requestErrorList' => [], 'documentStatusList' => [['documentNo' => $submission->fiscalDocument->document_no, 'documentStatus' => $status, 'errorList' => []]]], JSON_THROW_ON_ERROR);

    return new AgtInvoiceStatusResult(true, false, '/obterEstado', 200, $request, hash('sha256', $request), $response, hash('sha256', $response), $code, [], [new AgtDocumentStatusResult($submission->fiscalDocument->document_no, $status, [])], 'POISON SECRET remote message', 1);
}

test('bare legacy valid remains a legacy summary but never authorizes a receipt', function () {
    $submission = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    expect(CurrentAgtState::legacyWorkflowStatus($submission->fiscalDocument)->value)->toBe('valid')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and(CurrentAgtState::status($submission->fiscalDocument)->value)->toBe('unknown');
});

test('a current bound V records evidence atomically and repeated completion is idempotent', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $frozen = $submission->fiscalDocument->getAttributes();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect($claim)->not->toBeNull()
        ->and(AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus))->toBeNull();
    $result = observationResult($claim);
    $apply = function (AgtSubmission $locked): void {
        $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    };
    AgtSubmissionExecution::complete($claim, $result, $apply);
    $before = $submission->fresh()->qualified_projection;
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and($before['classification'])->toBe('authoritative')
        ->and($before['reported_state'])->toBe('valid')
        ->and($before)->toBe(AgtObservationReducer::rebuild($submission->fresh()));
    AgtSubmissionExecution::complete($claim, $result, $apply);
    expect($submission->attempts()->count())->toBe(1)
        ->and($submission->fresh()->qualified_projection)->toBe($before)
        ->and($submission->fiscalDocument->fresh()->getAttributes())->toBe($frozen)
        ->and(json_encode($before))->not->toContain('POISON');
});

test('unknown document status cannot become an invalid or valid authoritative result', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim, 'UNRECOGNIZED'), function (AgtSubmission $locked): void {
        $locked->update(['status' => AgtSubmissionStatus::Failed, 'next_attempt_at' => null]);
    });
    expect($submission->fresh()->qualified_projection['reported_state'])->toBeNull()
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
});

test('journal protects observations from raw SQL changes and cross-submission evidence references', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $entry = AgtSubmissionObservation::query()->firstOrFail();
    expect(fn () => DB::table('agt_submission_observations')->where('id', $entry->id)->update(['kind' => 'result']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('agt_submission_observations')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
});

test('conflicting duplicate evidence is retained and cannot preserve acceptance', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    AgtSubmissionExecution::complete($claim, observationResult($claim, 'I'), fn () => throw new RuntimeException('Duplicate must not apply'));
    expect($submission->fresh()->qualified_projection['classification'])->toBe('conflicting')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and($submission->attempts()->count())->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('kind', 'conflict')->count())->toBe(1);
    AgtSubmissionExecution::complete($claim, observationResult($claim, 'I'), fn () => throw new RuntimeException('Duplicate must not apply'));
    expect(AgtSubmissionObservation::query()->where('kind', 'conflict')->count())->toBe(1);
});

test('an expired older V is retained without regressing a newer V or freshness', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $older = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subMinute(), 'next_attempt_at' => null]);
    $newer = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($newer, observationResult($newer), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $state = $submission->fresh()->qualified_projection;
    AgtSubmissionExecution::complete($older, observationResult($older), fn () => throw new RuntimeException('Older result must not apply'));
    expect(Arr::except($submission->fresh()->qualified_projection, ['projected_at']))->toBe(Arr::except($state, ['projected_at']))
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and($submission->attempts()->pluck('attempt_number')->all())->toBe([1, 2]);
});

test('result transaction rollback retains the claim and permits one deterministic retry', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect(fn () => AgtSubmissionExecution::complete($claim, observationResult($claim), fn () => throw new RuntimeException('Injected failure')))->toThrow(RuntimeException::class);
    expect($submission->attempts()->count())->toBe(0)
        ->and(AgtSubmissionObservation::query()->where('kind', 'result')->count())->toBe(0)
        ->and($submission->fresh()->active_operation_uuid)->toBe($claim->active_operation_uuid);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
});

test('required projection audit failure rolls back claim evidence and lease', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    activity()->disableLogging();
    expect(fn () => AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus))->toThrow(RuntimeException::class);
    expect(AgtSubmissionObservation::query()->count())->toBe(0)
        ->and($submission->fresh()->active_operation_uuid)->toBeNull();
});

test('unknown callbacks cannot fail a different operation and recovery uses a durable lease', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus, '11111111-1111-4111-8111-111111111111', 3);
    AgtSubmissionExecution::fail($submission->id, '22222222-2222-4222-8222-222222222222', 3);
    expect($submission->fresh()->active_operation_uuid)->toBe($claim->active_operation_uuid);
    AgtSubmissionExecution::fail($submission->id, '11111111-1111-4111-8111-111111111111', 3);
    expect($submission->fresh()->status)->toBe(AgtSubmissionStatus::Failed)
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
});

test('bare legacy V reconstruction is unknown and repeated rebuild is deterministic', function () {
    $submission = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    $frozen = $submission->fiscalDocument->getAttributes();
    AgtReconstruction::rebuild($submission->id);
    $state = $submission->fresh()->qualified_projection;
    AgtReconstruction::rebuild($submission->id);
    expect($state['reported_state'])->toBeNull()
        ->and($state['classification'])->toBe('unknown')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and($submission->fresh()->status)->toBe(AgtSubmissionStatus::Valid)
        ->and($submission->fresh()->qualified_projection)->toBe($state)
        ->and(AgtSubmissionObservation::query()->where('kind', 'legacy_import')->count())->toBe(1)
        ->and($submission->fiscalDocument->fresh()->getAttributes())->toBe($frozen);
});

test('stale proven acceptance remains eligible and bounded explanations exclude poisoned messages', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null, 'safe_message' => 'POISON SECRET /signing/key']));
    $observed = CarbonImmutable::parse($submission->fresh()->qualified_projection['observed_at']);
    $presentation = AgtStatusPresentation::describe($submission->fiscalDocument->fresh(), $observed->addMinutes(15));
    expect($presentation['freshness'])->toBe('stale')
        ->and($presentation['explanation']['code'])->toBe('stale_observation')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and(json_encode($presentation))->not->toContain('POISON', '/signing/key');
});

function legacyEvidenceSubmission(int $attempts = 1): AgtSubmission
{
    $document = FiscalDocument::factory()->issued()->create(['issued_at' => now()->subDays(31), 'frozen_at' => now()->subDays(31)]);

    return AgtSubmission::factory()->for($document, 'fiscalDocument')->received()->create(['status' => AgtSubmissionStatus::Valid,
        'attempt_count' => $attempts, 'next_attempt_at' => null, 'created_at' => now()->subDays(31), 'received_at' => now()->subDays(31)]);
}

function legacyObservationAttempt(AgtSubmission $submission, int $sequence, string $status = 'V'): AgtSubmissionAttempt
{
    $result = observationResult($submission, $status);

    return $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
        'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => $sequence, 'endpoint_path' => $result->endpoint,
        'request_body' => $result->requestBody, 'request_body_sha256' => $result->requestBodySha256,
        'response_body' => $result->responseBody, 'response_body_sha256' => $result->responseBodySha256, 'http_status' => 200,
        'result_code' => $result->resultCode, 'error_codes' => [], 'safe_message' => 'POISON SECRET historical message',
        'started_at' => now()->subDays(30)->addMinutes($sequence), 'completed_at' => now()->subDays(30)->addMinutes($sequence)->addSecond()])->fresh();
}

test('complete legacy acceptance reconstructs without refreshing its age or changing fiscal evidence', function () {
    $submission = legacyEvidenceSubmission();
    $attempt = legacyObservationAttempt($submission, 1);
    $before = [$submission->fiscalDocument->getAttributes(), $attempt->getAttributes(), $submission->request_body];
    AgtReconstruction::rebuild($submission->id);
    $state = $submission->fresh()->qualified_projection;
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and(CarbonImmutable::parse($state['observed_at'])->getTimestamp())->toBe($attempt->completed_at->getTimestamp())
        ->and(AgtStatusPresentation::describe($submission->fiscalDocument->fresh(), CarbonImmutable::now())['freshness'])->toBe('stale');
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection)->toBe($state)
        ->and([$submission->fiscalDocument->fresh()->getAttributes(), $attempt->fresh()->getAttributes(), $submission->fresh()->request_body])->toBe($before);
});

test('legacy missing hash gaps overlapping times and contradictory evidence never manufacture acceptance', function (string $defect) {
    $submission = legacyEvidenceSubmission(2);
    $first = legacyObservationAttempt($submission, 1);
    $second = legacyObservationAttempt($submission, $defect === 'gap' ? 3 : 2, $defect === 'opposed' ? 'I' : 'V');
    if ($defect === 'hash') {
        DB::table('agt_submission_attempts')->where('id', $second->id)->update(['response_body_sha256' => str_repeat('0', 64)]);
    } elseif ($defect === 'overlap') {
        DB::table('agt_submission_attempts')->where('id', $second->id)->update(['started_at' => $first->started_at]);
    } elseif ($defect === 'decrypt') {
        DB::table('agt_submission_attempts')->where('id', $second->id)->update(['response_body' => 'not decryptable']);
    }
    AgtReconstruction::rebuild($submission->id);
    $state = $submission->fresh()->qualified_projection;
    expect($state['reported_state'])->toBeNull()
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and($state['last_known_state'])->toBe('valid');
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection)->toBe($state);
})->with(['hash', 'gap', 'overlap', 'decrypt', 'opposed']);

test('cross-submission projection references and unknown enum data are rejected at the database boundary', function () {
    $first = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $second = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($first->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $state = $first->fresh()->qualified_projection;
    expect(fn () => DB::transaction(fn () => $second->update(['qualified_projection' => $state])))->toThrow(QueryException::class);
    $state['knowledge'] = 'POISON SECRET';
    expect(fn () => DB::transaction(fn () => $first->update(['qualified_projection' => $state])))->toThrow(QueryException::class);
});

test('populated additive migration preserves bytes and refuses destructive rollback', function () {
    $schema = require database_path('migrations/2026_10_07_160233_add_agt_observation_projection.php');
    $schema->down();
    $submission = legacyEvidenceSubmission();
    $attempt = legacyObservationAttempt($submission, 1);
    $before = [$submission->fiscalDocument->getAttributes(), $attempt->getAttributes(), $submission->request_body];
    $schema->up();
    $backfill = require database_path('migrations/2026_10_07_162420_reconstruct_agt_observation_projections.php');
    $backfill->up();
    $state = $submission->fresh()->qualified_projection;
    $backfill->up();
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and($submission->fresh()->qualified_projection)->toBe($state)
        ->and([$submission->fiscalDocument->fresh()->getAttributes(), $attempt->fresh()->getAttributes(), $submission->fresh()->request_body])->toBe($before)
        ->and(fn () => $backfill->down())->toThrow(RuntimeException::class)
        ->and(fn () => $schema->down())->toThrow(RuntimeException::class);
});

test('normalized evidence mapping is explicit for recognized unsupported and contradictory response shapes', function (array $patch, string $classification, ?string $reported, bool $successful) {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $base = observationResult($submission);
    $response = json_decode($base->responseBody, true, flags: JSON_THROW_ON_ERROR);
    if (array_key_exists('documentStatusList', $patch) && $patch['documentStatusList'] === 'opposed') {
        $patch['documentStatusList'] = [$response['documentStatusList'][0], [...$response['documentStatusList'][0], 'documentStatus' => 'I']];
    } elseif (($patch['documentStatusList'] ?? null) === 'identical') {
        $patch['documentStatusList'] = [$response['documentStatusList'][0], $response['documentStatusList'][0]];
    } elseif (($patch['documentStatusList'] ?? null) === 'unknown') {
        $patch['documentStatusList'] = [[...$response['documentStatusList'][0], 'documentStatus' => 'POISON SECRET']];
    } elseif (($patch['documentStatusList'] ?? null) === 'V_errors') {
        $patch['documentStatusList'] = [[...$response['documentStatusList'][0], 'errorList' => ['POISON SECRET']]];
    } elseif (($patch['documentStatusList'] ?? null) === 'I') {
        $patch['documentStatusList'] = [[...$response['documentStatusList'][0], 'documentStatus' => 'I']];
    }
    $body = json_encode(array_replace($response, $patch), JSON_THROW_ON_ERROR);
    $result = new AgtInvoiceStatusResult(true, false, $base->endpoint, 200, $base->requestBody, $base->requestBodySha256,
        $body, hash('sha256', $body), $base->resultCode, [], $base->documents, 'POISON SECRET', 1);
    $outcome = AgtEvidence::interpret($submission, $result);
    expect($outcome['classification'])->toBe($classification)
        ->and($outcome['reported_state'])->toBe($reported)
        ->and($outcome['successful_sync'])->toBe($successful)
        ->and(json_encode($outcome))->not->toContain('POISON');
})->with([
    'target V' => [[], 'authoritative', 'valid', true],
    'mixed response matching V' => [['resultCode' => '1'], 'authoritative', 'valid', true],
    'target I' => [['resultCode' => '2', 'documentStatusList' => 'I'], 'authoritative', 'invalid', true],
    'deferred 7' => [['resultCode' => '7'], 'unknown', null, false],
    'processing 8' => [['resultCode' => '8'], 'authoritative', 'processing', true],
    'processing cancellation 9' => [['resultCode' => '9'], 'authoritative', 'processing_cancelled', true],
    'unsupported code' => [['resultCode' => 'POISON SECRET'], 'unknown', null, false],
    'invalid type' => [['resultCode' => true], 'unknown', null, false],
    'missing target' => [['documentStatusList' => []], 'unknown', null, false],
    'equal targets' => [['documentStatusList' => 'identical'], 'authoritative', 'valid', true],
    'opposed targets' => [['documentStatusList' => 'opposed'], 'conflicting', null, false],
    'unsupported document code' => [['documentStatusList' => 'unknown'], 'unknown', null, false],
    'V with errors' => [['documentStatusList' => 'V_errors'], 'conflicting', null, false],
    'V with rejection code' => [['resultCode' => '2'], 'conflicting', null, false],
    'I with success code' => [['documentStatusList' => 'I'], 'conflicting', null, false],
]);

test('qualified object and scoped SQL predicates agree without borrowing legacy fiscal validity', function () {
    $known = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($known->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $unknown = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    foreach ([$known->fiscalDocument->fresh(), $unknown->fiscalDocument->fresh()] as $document) {
        foreach (['valid', 'invalid', 'unknown', 'pending', 'received'] as $state) {
            expect(CurrentAgtState::matching(FiscalDocument::query()->whereKey($document->id), [$state])->exists())
                ->toBe(CurrentAgtState::status($document)->value === $state);
        }
    }
});

test('qualified DTO is closed and future observation time is displayed as uncertain', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $state = $submission->fresh()->qualified_projection;
    $asOf = CarbonImmutable::parse($state['observed_at'])->subSecond();
    $view = AgtStatusPresentation::describe($submission->fiscalDocument->fresh(), $asOf);
    expect(array_keys($view))->toBe(['version', 'public_id', 'fiscal_state', 'delivery_state', 'reported_state', 'knowledge', 'sync', 'freshness', 'observed_at', 'effective_at', 'last_successful_sync_at', 'as_of', 'provenance', 'explanation'])
        ->and($view['freshness'])->toBe('stale')->and($view['effective_at'])->toBeNull()
        ->and($view['reported_state'])->toBeNull()
        ->and(CurrentAgtState::status($submission->fiscalDocument->fresh(), $asOf)->value)->toBe('unknown')
        ->and(CurrentAgtState::matching(FiscalDocument::query()->whereKey($submission->fiscal_document_id), ['valid'], $asOf)->exists())->toBeFalse()
        ->and(CurrentAgtState::matching(FiscalDocument::query()->whereKey($submission->fiscal_document_id), ['unknown'], $asOf)->exists())->toBeTrue();
    $corrupt = [...$state, 'extra_secret' => 'POISON SECRET'];
    expect(AgtObservationReducer::validProjection($corrupt))->toBeFalse();
});

test('interrupted reconstruction migration rolls back and can resume without duplicate import evidence', function () {
    $first = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    $second = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "activity_log"') && ++$writes === 1) {
            activity()->disableLogging();
        }
    });
    $migration = require database_path('migrations/2026_10_07_162420_reconstruct_agt_observation_projections.php');
    try {
        expect(fn () => DB::transaction(fn () => $migration->up()))->toThrow(RuntimeException::class);
    } finally {
        activity()->enableLogging();
    }
    expect(AgtSubmissionObservation::query()->count())->toBe(0)
        ->and($first->fresh()->qualified_projection)->toBeNull()
        ->and($second->fresh()->qualified_projection)->toBeNull();
    DB::transaction(fn () => $migration->up());
    expect(AgtSubmissionObservation::query()->where('kind', 'legacy_import')->count())->toBe(2)
        ->and(CurrentAgtState::validated($first->fiscalDocument))->toBeFalse()
        ->and(CurrentAgtState::validated($second->fiscalDocument))->toBeFalse();
});

test('future legacy timestamps and transport-only evidence fail closed without rewriting workflow', function (string $defect) {
    $submission = legacyEvidenceSubmission();
    $attempt = legacyObservationAttempt($submission, 1);
    if ($defect === 'future') {
        DB::table('agt_submission_attempts')->where('id', $attempt->id)->update(['started_at' => now()->addDay(), 'completed_at' => now()->addDay()->addSecond()]);
    } else {
        $body = json_encode(['requestID' => $submission->request_id], JSON_THROW_ON_ERROR);
        DB::table('agt_submission_attempts')->where('id', $attempt->id)->update(['operation' => AgtSubmissionAttemptOperation::RegisterInvoice->value,
            'request_body' => Crypt::encryptString($submission->request_body), 'request_body_sha256' => $submission->request_body_sha256,
            'response_body' => Crypt::encryptString($body), 'response_body_sha256' => hash('sha256', $body)]);
    }
    AgtReconstruction::rebuild($submission->id);
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and($submission->fresh()->qualified_projection['reported_state'])->toBeNull()
        ->and($submission->fresh()->status)->toBe(AgtSubmissionStatus::Valid);
})->with(['future', 'transport']);

test('new UTC journal leases agree with existing application-timezone attempt and due timestamps', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect($claim->next_attempt_at->getTimestamp())->toBe($claim->operation_lease_expires_at->getTimestamp());
    AgtSubmissionExecution::complete($claim, observationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $entry = AgtSubmissionObservation::query()->where('kind', 'result')->firstOrFail();
    $attempt = $submission->attempts()->firstOrFail();
    expect($attempt->started_at->getTimestamp())->toBe($entry->started_at->getTimestamp())
        ->and($attempt->completed_at->getTimestamp())->toBe($entry->observed_at->getTimestamp());
});

test('AGT notification feed preserves user and workspace isolation on SQLite and PostgreSQL text storage', function () {
    $user = User::factory()->withWorkspace()->create();
    $foreign = User::factory()->withWorkspace()->create();
    foreach ([null, $user->current_workspace_id, $foreign->current_workspace_id] as $workspaceId) {
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => DocumentAcceptedByAgt::class,
            'data' => ['workspace_id' => $workspaceId, 'title' => 'Stored historical notification', 'body' => 'Historical transition']]);
    }
    $foreign->notifications()->create(['id' => (string) Str::uuid(), 'type' => DocumentAcceptedByAgt::class,
        'data' => ['workspace_id' => $user->current_workspace_id]]);
    $feed = app(NotificationFeed::class);
    expect($feed->unreadCount($user, $user->current_workspace_id))->toBe(2)
        ->and($feed->recent($user, $user->current_workspace_id))->toHaveCount(2)
        ->and($feed->unreadCount($user, $foreign->current_workspace_id))->toBe(2)
        ->and($feed->unreadCount($user, null))->toBe(3);
});

test('system projection audit ignores ambient human impersonation context and separates operator attribution', function () {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Context::add(['impersonator_id' => 999, 'impersonation_session' => 'POISON SECRET',
        'request_id' => 'POISON SECRET', 'automation_id' => 'POISON SECRET', 'request_ip' => 'POISON SECRET']);
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $activity = Activity::query()->where('log_name', 'agt_projection')->latest('id')->firstOrFail();
    expect($activity->causer_id)->toBeNull()->and($activity->properties['actor_kind'])->toBe('system')
        ->and($activity->properties['real_actor_id'])->toBeNull()->and($activity->properties['effective_actor_id'])->toBeNull()
        ->and($activity->properties['request_id'])->not->toBe($claim->active_operation_uuid)
        ->and(json_encode($activity->properties))->not->toContain('POISON');
    $legacy = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
    AgtReconstruction::rebuild($legacy->id, 503, 'maintenance_command');
    $activity = Activity::query()->where('subject_id', $legacy->id)->where('log_name', 'agt_projection')->latest('id')->firstOrFail();
    expect($activity->properties['operator_unix_uid'])->toBe(503)
        ->and($activity->properties['operator_kind'])->toBe('maintenance_command')
        ->and($activity->causer_id)->toBeNull();
});
