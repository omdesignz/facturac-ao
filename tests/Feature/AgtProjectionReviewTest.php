<?php

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Analytics\DocumentSnapshot;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Documents\AgtEvidence;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\Documents\CurrentAgtState;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function reviewBoundSubmission(): AgtSubmission
{
    $document = FiscalDocument::factory()->issued()->create();
    $body = json_encode(['schemaVersion' => '2.0', 'taxRegistrationNumber' => $document->legalEntity->tax_identification_number,
        'numberOfEntries' => 1, 'documents' => [['documentNo' => $document->document_no]]], JSON_THROW_ON_ERROR);

    return AgtSubmission::factory()->received()->create(['fiscal_document_id' => $document->id, 'request_body' => $body,
        'request_body_sha256' => hash('sha256', $body), 'next_attempt_at' => null]);
}

function reviewResult(AgtSubmission $submission, array $patch = []): AgtInvoiceStatusResult
{
    $request = json_encode(['requestID' => $submission->request_id, 'schemaVersion' => '2.0',
        'taxRegistrationNumber' => json_decode($submission->request_body, true)['taxRegistrationNumber']], JSON_THROW_ON_ERROR);
    $body = json_encode(array_replace_recursive(['resultCode' => '0', 'requestErrorList' => [], 'documentStatusList' => [[
        'documentNo' => $submission->fiscalDocument->document_no, 'documentStatus' => 'V', 'errorList' => [],
    ]]], $patch), JSON_THROW_ON_ERROR);

    return new AgtInvoiceStatusResult(true, false, '/obterEstado', 200, $request, hash('sha256', $request), $body,
        hash('sha256', $body), '0', [], [], 'POISON SECRET https://internal.invalid', 1);
}

test('review malformed error containers cannot establish authoritative V', function (array $patch) {
    $submission = reviewBoundSubmission();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $result = reviewResult($claim, $patch);
    AgtSubmissionExecution::complete($claim, $result, fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse()
        ->and(AgtEvidence::interpret($claim, $result)['reported_state'])->toBeNull();
})->with([
    'boolean request errors' => [['requestErrorList' => false]],
    'zero document errors' => [['documentStatusList' => [['errorList' => '0']]]],
    'object document errors' => [['documentStatusList' => [['errorList' => (object) []]]]],
]);

test('review receipt evidence binds frozen taxpayer identity rather than mutable company data', function () {
    $submission = reviewBoundSubmission();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, reviewResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeTrue();
    $submission->legalEntity->update(['tax_identification_number' => '9999999999']);
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument->fresh()))->toBeTrue();
});

test('review database rejects null normalized evidence enums', function () {
    $submission = reviewBoundSubmission();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $outcome = ['version' => 1, 'classification' => null, 'knowledge' => 'known', 'reported_state' => 'valid',
        'sync' => 'idle', 'delivery_state' => 'acknowledged', 'reason' => 'validation_reported', 'successful_sync' => true, 'eligible_generation' => true];
    expect(fn () => DB::transaction(fn () => AgtSubmissionExecution::append($submission, $claim->active_operation_uuid,
        $claim->operation_sequence, 'failure', $outcome, AgtSubmissionExecution::databaseNow(), AgtSubmissionExecution::databaseNow())))->toThrow(QueryException::class);
});

test('review duplicate JSON members cannot hide a contradictory target result', function () {
    $submission = reviewBoundSubmission();
    $base = reviewResult($submission);
    $body = str_replace('"documentStatus":"V"', '"documentStatus":"I","documentStatus":"V"', $base->responseBody);
    $result = new AgtInvoiceStatusResult(true, false, $base->endpoint, 200, $base->requestBody, $base->requestBodySha256,
        $body, hash('sha256', $body), '0', [], [], '', 1);
    expect(AgtEvidence::interpret($submission, $result)['reported_state'])->toBeNull();
});

test('review unknown snapshot is attention and uses its supplied observation instant', function () {
    $submission = reviewBoundSubmission();
    $view = app(DocumentSnapshot::class)->describe($submission->fiscalDocument, CarbonImmutable::now());
    expect($view['status'])->toBe('unknown')->and($view['steps'][3]['state'])->toBe('error');
});

test('review valid evidence cannot omit the frozen taxpayer binding', function () {
    $submission = reviewBoundSubmission();
    $result = reviewResult($submission);
    $body = json_decode($submission->request_body, true);
    unset($body['taxRegistrationNumber']);
    $bytes = json_encode($body, JSON_THROW_ON_ERROR);
    $submission->setRawAttributes([...$submission->getAttributes(), 'request_body' => Crypt::encryptString($bytes), 'request_body_sha256' => hash('sha256', $bytes)]);
    expect(AgtEvidence::interpret($submission, $result)['reported_state'])->toBeNull();
});

test('review result cannot borrow an older attempt from the same aggregate', function () {
    $submission = reviewBoundSubmission();
    $first = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($first, reviewResult($first), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Processing, 'next_attempt_at' => null]));
    $original = AgtSubmissionObservation::query()->where('kind', 'result')->firstOrFail();
    $second = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect(fn () => DB::transaction(fn () => AgtSubmissionExecution::append($submission, $second->active_operation_uuid,
        $second->operation_sequence, 'result', $original->outcome, AgtSubmissionExecution::databaseNow(), AgtSubmissionExecution::databaseNow(), $original->attempt_id)))->toThrow(QueryException::class);
});

test('review legacy sending work gets a local recovery fence without fabricated remote history', function () {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Sending, 'request_id' => null, 'attempt_count' => 1]);
    $before = $submission->fresh()->getAttributes();
    AgtReconstruction::rebuild($submission->id);
    $submission->refresh();
    expect($submission->active_operation_uuid)->not->toBeNull()
        ->and($submission->attempts()->count())->toBe(0)
        ->and($submission->qualified_projection['reported_state'])->toBeNull();
    foreach ($before as $key => $value) {
        if (! in_array($key, ['operation_sequence', 'active_operation_uuid', 'operation_lease_expires_at', 'projection_revision', 'qualified_projection', 'updated_at'], true)) {
            expect($submission->getRawOriginal($key))->toBe($value);
        }
    }
    $state = $submission->qualified_projection;
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection)->toBe($state)
        ->and(AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::RegisterInvoice))->toBeNull();
    $oldOperation = $submission->active_operation_uuid;
    $submission->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subSecond()]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::RegisterInvoice);
    expect($claim)->not->toBeNull()->and($claim->active_operation_uuid)->not->toBe($oldOperation)
        ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse();
});

test('review acknowledged identity cannot be submitted again by a stale workflow label', function () {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Retrying]);
    expect(AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::RegisterInvoice))->toBeNull();
});

test('review incomplete legacy hashes stay partial rather than irreversible conflict', function (string $missing) {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Valid, 'attempt_count' => 1]);
    $result = reviewResult($submission);
    $attempt = $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
        'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => 1, 'endpoint_path' => '/obterEstado',
        'request_body' => $result->requestBody, 'request_body_sha256' => $missing === 'request' ? '' : $result->requestBodySha256,
        'response_body' => $result->responseBody, 'response_body_sha256' => $missing === 'response' ? null : $result->responseBodySha256,
        'http_status' => 200, 'safe_message' => 'POISON SECRET', 'started_at' => now(), 'completed_at' => now()]);
    $before = $attempt->fresh()->getAttributes();
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection['classification'])->toBe('partial')
        ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse()
        ->and($attempt->fresh()->getAttributes())->toBe($before);
})->with(['request', 'response']);

test('review database rejects projection facets that disagree with the operational summary', function () {
    $submission = reviewBoundSubmission();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, reviewResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    $projection = $submission->fresh()->qualified_projection;
    $projection['sync'] = 'failed';
    expect(fn () => DB::transaction(fn () => DB::table('agt_submissions')->where('id', $submission->id)->update([
        'qualified_projection' => json_encode($projection, JSON_THROW_ON_ERROR),
    ])))->toThrow(QueryException::class);
});

test('review completion rejects a caller snapshot with a substituted request identity', function () {
    $submission = reviewBoundSubmission();
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $claim->request_id = 'substituted-request';
    expect(fn () => AgtSubmissionExecution::complete($claim, reviewResult($claim), fn () => null))->toThrow(DomainException::class);
    expect($submission->attempts()->count())->toBe(0)
        ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse();
});

test('review legacy reconstruction cannot skip an unverified earlier attempt to select later V', function (string $missing) {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Valid, 'attempt_count' => 2]);
    $result = reviewResult($submission);
    foreach ([1, 2] as $sequence) {
        $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => $sequence, 'endpoint_path' => '/obterEstado',
            'request_body' => $result->requestBody, 'request_body_sha256' => $sequence === 1 && $missing === 'request hash' ? '' : $result->requestBodySha256,
            'response_body' => $sequence === 1 && $missing === 'successful response' ? null : $result->responseBody,
            'response_body_sha256' => $sequence === 1 && $missing === 'successful response' ? null : $result->responseBodySha256,
            'http_status' => 200, 'safe_message' => '', 'started_at' => now(), 'completed_at' => now()]);
    }
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection['classification'])->toBe('partial')
        ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse();
})->with(['request hash', 'successful response']);

test('review contradiction remains sticky when additional legacy history is incomplete', function () {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Valid, 'attempt_count' => 3]);
    foreach ([1 => 'V', 2 => 'I', 3 => 'V'] as $sequence => $status) {
        $result = reviewResult($submission, ['resultCode' => $status === 'I' ? '2' : '0', 'documentStatusList' => [['documentStatus' => $status]]]);
        $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => $sequence, 'endpoint_path' => '/obterEstado',
            'request_body' => $result->requestBody, 'request_body_sha256' => $result->requestBodySha256,
            'response_body' => $result->responseBody, 'response_body_sha256' => $result->responseBodySha256,
            'http_status' => 200, 'safe_message' => '', 'started_at' => now(), 'completed_at' => $sequence === 3 ? null : now()]);
    }
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection['classification'])->toBe('conflicting')
        ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse();
    $before = $submission->fresh()->qualified_projection;
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection)->toBe($before);
});

test('review an early incomplete attempt cannot hide later contradictory protected history', function () {
    $submission = reviewBoundSubmission();
    $submission->update(['status' => AgtSubmissionStatus::Processing, 'attempt_count' => 3]);
    foreach ([1 => 'V', 2 => 'V', 3 => 'I'] as $sequence => $status) {
        $result = reviewResult($submission, ['resultCode' => $status === 'I' ? '2' : '0', 'documentStatusList' => [['documentStatus' => $status]]]);
        $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => $sequence, 'endpoint_path' => '/obterEstado',
            'request_body' => $result->requestBody, 'request_body_sha256' => $sequence === 1 ? '' : $result->requestBodySha256,
            'response_body' => $result->responseBody, 'response_body_sha256' => $result->responseBodySha256,
            'http_status' => 200, 'safe_message' => '', 'started_at' => now(), 'completed_at' => now()]);
    }
    AgtReconstruction::rebuild($submission->id);
    expect($submission->fresh()->qualified_projection['classification'])->toBe('conflicting');
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    AgtSubmissionExecution::complete($claim, reviewResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
    expect(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse();
});
