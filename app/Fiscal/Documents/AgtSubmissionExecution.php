<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\RequiredAudit;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgtSubmissionExecution
{
    /** Call only inside a transaction: the parent lock precedes every aggregate lock. */
    public static function locked(int $id, bool $allowQuarantine = false): ?AgtSubmission
    {
        $parent = AgtSubmission::query()->useWritePdo()->whereKey($id)->value('fiscal_document_id');
        if ($parent === null || ! FiscalDocument::query()->useWritePdo()->whereKey($parent)->lockForUpdate()->first()) {
            return null;
        }
        $submission = AgtSubmission::query()->useWritePdo()->with(['agtConnection', 'legalEntity', 'fiscalDocument'])->whereKey($id)->lockForUpdate()->first();
        if ($submission === null || $submission->fiscal_document_id !== $parent) {
            return null;
        }
        if ($submission->environment !== $submission->fiscalDocument->environment
            || ($submission->environment !== $submission->agtConnection->environment->value && ! ($allowQuarantine && $submission->environment === 'unresolved'))) {
            throw new \DomainException('AGT outbox identity does not match its destination.');
        }

        return $submission;
    }

    public static function databaseNow(): CarbonImmutable
    {
        $row = DB::selectOne(DB::getDriverName() === 'pgsql' ? 'SELECT clock_timestamp() AS observed_time' : 'SELECT CURRENT_TIMESTAMP AS observed_time');

        return CarbonImmutable::parse($row->observed_time, 'UTC');
    }

    public static function claim(int $id, AgtSubmissionAttemptOperation $operation, ?string $executionId = null, int $queueAttempt = 1): ?AgtSubmission
    {
        return DB::transaction(function () use ($id, $operation, $executionId, $queueAttempt): ?AgtSubmission {
            $submission = self::locked($id);
            if ($submission === null) {
                return null;
            }
            $now = self::databaseNow();
            if ($submission->operation_lease_expires_at?->gt($now)) {
                return null;
            }
            $registration = $operation === AgtSubmissionAttemptOperation::RegisterInvoice;
            if ($registration && filled($submission->request_id)) {
                return null;
            }
            $recovery = $submission->status === AgtSubmissionStatus::Sending && $submission->operation_lease_expires_at?->lte($now);
            if ($registration ? (! $submission->status->canSubmit() && ! $recovery) : (! $submission->status->canPoll() || blank($submission->request_id))) {
                return null;
            }
            if (! $recovery && $submission->next_attempt_at?->gt($now)) {
                return null;
            }
            $sequence = max((int) $submission->operation_sequence, $submission->attempt_count, (int) $submission->attempts()->max('attempt_number')) + 1;
            $uuid = (string) Str::uuid();
            $entry = self::append($submission, $uuid, $sequence, 'claim', ['version' => 1, 'operation' => $operation->value,
                'execution_uuid' => $executionId ?? $uuid, 'queue_attempt' => $queueAttempt, 'request_identity_sha256' => hash('sha256', $submission->request_id ?? ''), 'frozen_request_sha256' => $submission->request_body_sha256], $now, null);
            $submission->update(['operation_sequence' => $sequence, 'attempt_count' => $sequence, 'active_operation_uuid' => $uuid,
                'operation_lease_expires_at' => $now->addMinutes(2), 'next_attempt_at' => $now->addMinutes(2)->setTimezone(config()->string('app.timezone')),
                ...($registration ? ['status' => AgtSubmissionStatus::Sending, 'submitted_at' => $submission->submitted_at ?? $now->setTimezone(config()->string('app.timezone'))] : [])]);
            self::project($submission, $entry);

            return $submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']);
        }, 3);
    }

    public static function fail(int $id, string $executionId, int $queueAttempt): void
    {
        DB::transaction(function () use ($id, $executionId, $queueAttempt): void {
            $submission = self::locked($id);
            if ($submission === null) {
                return;
            }
            $claim = AgtSubmissionObservation::query()->where('agt_submission_id', $id)->where('kind', 'claim')
                ->where('outcome->execution_uuid', $executionId)->where('outcome->queue_attempt', $queueAttempt)->first();
            if ($claim === null || AgtSubmissionObservation::query()->where('operation_uuid', $claim->operation_uuid)->where('kind', 'failure')->exists()) {
                return;
            }
            $eligible = $submission->active_operation_uuid === $claim->operation_uuid
                && ! AgtSubmissionObservation::query()->where('operation_uuid', $claim->operation_uuid)->where('kind', 'result')->exists();
            $entry = self::append($submission, $claim->operation_uuid, $claim->operation_sequence, 'failure',
                ['version' => 1, 'eligible_generation' => $eligible, 'classification' => 'unknown', 'knowledge' => 'unknown_response',
                    'reported_state' => null, 'sync' => 'failed', 'delivery_state' => 'failed', 'reason' => 'sync_failed', 'successful_sync' => false],
                $claim->started_at, self::databaseNow());
            if ($eligible) {
                $submission->update(['active_operation_uuid' => null, 'operation_lease_expires_at' => null,
                    'status' => AgtSubmissionStatus::Failed, 'next_attempt_at' => null, 'failed_at' => now(),
                    'safe_message' => 'A comunicação requer revisão da evidência.']);
            }
            self::project($submission, $entry);
        }, 3);
    }

    /** @param Closure(AgtSubmission, array<string, mixed>): mixed $apply */
    public static function complete(AgtSubmission $claim, AgtInvoiceStatusResult|AgtRegistrationResult $result, Closure $apply): mixed
    {
        return DB::transaction(function () use ($claim, $result, $apply): mixed {
            $submission = self::locked($claim->id);
            if ($submission === null) {
                return null;
            }
            $start = AgtSubmissionObservation::query()->where('operation_uuid', $claim->active_operation_uuid)->where('kind', 'claim')->firstOrFail();
            if ($start->agt_submission_id !== $submission->id || $start->workspace_id !== $submission->workspace_id
                || $start->legal_entity_id !== $submission->legal_entity_id || $start->operation_sequence !== $claim->operation_sequence
                || ! hash_equals($start->outcome['request_identity_sha256'], hash('sha256', $claim->request_id ?? ''))
                || ! hash_equals($start->outcome['frozen_request_sha256'], $claim->request_body_sha256)
                || ! hash_equals($submission->request_body_sha256, $claim->request_body_sha256)
                || $claim->fiscal_document_id !== $submission->fiscal_document_id || $claim->environment !== $submission->environment) {
                throw new \DomainException('AGT completion does not match its durable claim.');
            }
            $outcome = AgtEvidence::interpret($claim, $result);
            $outcome['response_sha256'] = $result->responseBodySha256;
            $outcome['request_sha256'] = $result->requestBodySha256;
            $digest = hash('sha256', app(CanonicalJson::class)->encode($outcome));
            $existing = AgtSubmissionObservation::query()->where('operation_uuid', $claim->active_operation_uuid)->where('kind', 'result')->first();
            if ($existing !== null) {
                if (hash_equals($existing->outcome['evidence_digest'], $digest)) {
                    return null;
                }
                $conflict = ['version' => 1, 'classification' => 'conflicting', 'evidence_digest' => $digest];
                $hash = hash('sha256', app(CanonicalJson::class)->encode($conflict));
                if (! AgtSubmissionObservation::query()->where('operation_uuid', $claim->active_operation_uuid)->where('kind', 'conflict')->where('outcome_sha256', $hash)->exists()) {
                    $entry = self::append($submission, $claim->active_operation_uuid, $claim->operation_sequence, 'conflict', $conflict, $start->started_at, self::databaseNow(), null, $result->responseBody);
                    self::project($submission, $entry);
                }

                return null;
            }
            $now = self::databaseNow();
            $eligible = $submission->active_operation_uuid === $claim->active_operation_uuid && $submission->operation_sequence === $claim->operation_sequence
                && $submission->operation_lease_expires_at?->gt($now)
                && $submission->request_id === $claim->request_id;
            $request = $result instanceof AgtInvoiceStatusResult ? $result->requestBody : $claim->request_body;
            $attempt = $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
                'operation' => $result instanceof AgtInvoiceStatusResult ? AgtSubmissionAttemptOperation::QueryStatus : AgtSubmissionAttemptOperation::RegisterInvoice,
                'attempt_number' => $claim->operation_sequence, 'endpoint_path' => $result->endpoint, 'request_body' => $request, 'request_body_sha256' => $result->requestBodySha256,
                'response_body' => $result->responseBody, 'response_body_sha256' => $result->responseBodySha256, 'http_status' => $result->httpStatus,
                'result_code' => $result instanceof AgtInvoiceStatusResult ? $result->resultCode : null,
                'error_codes' => $result instanceof AgtInvoiceStatusResult ? $result->requestErrorCodes : $result->errorCodes,
                'safe_message' => 'Resultado AGT registado; consulte o estado qualificado.', 'started_at' => $start->started_at->setTimezone(config()->string('app.timezone')), 'completed_at' => $now->setTimezone(config()->string('app.timezone'))]);
            $entry = self::append($submission, $claim->active_operation_uuid, $claim->operation_sequence, 'result', [...$outcome, 'eligible_generation' => $eligible, 'evidence_digest' => $digest], $start->started_at, $now, $attempt->id);
            $response = null;
            if ($eligible) {
                $submission->update(['active_operation_uuid' => null, 'operation_lease_expires_at' => null]);
                $response = $apply($submission, $outcome);
            }
            self::project($submission, $entry);

            return $response;
        }, 3);
    }

    /** @param array<string, mixed> $outcome */
    public static function append(AgtSubmission $submission, string $uuid, int $sequence, string $kind, array $outcome, CarbonImmutable $started, ?CarbonImmutable $observed, ?int $attempt = null, ?string $conflictBytes = null): AgtSubmissionObservation
    {
        return AgtSubmissionObservation::query()->create(['agt_submission_id' => $submission->id, 'workspace_id' => $submission->workspace_id,
            'legal_entity_id' => $submission->legal_entity_id, 'operation_uuid' => $uuid, 'operation_sequence' => $sequence, 'kind' => $kind,
            'reducer_version' => 1, 'outcome' => $outcome, 'outcome_sha256' => hash('sha256', app(CanonicalJson::class)->encode($outcome)),
            'attempt_id' => $attempt, 'started_at' => $started, 'observed_at' => $observed, 'recorded_at' => self::databaseNow(),
            'correlation_id' => AgtSubmissionObservation::query()->where('operation_uuid', $uuid)->where('kind', 'claim')->value('correlation_id') ?? (string) Str::uuid(), 'conflict_response_body' => $conflictBytes, 'conflict_response_sha256' => $conflictBytes === null ? null : hash('sha256', $conflictBytes)]);
    }

    public static function project(AgtSubmission $submission, AgtSubmissionObservation $entry, ?int $operatorUid = null, ?string $operatorKind = null): void
    {
        $projection = AgtObservationReducer::rebuild($submission);
        $submission->update(['qualified_projection' => $projection, 'reducer_version' => 1, 'projection_revision' => $submission->projection_revision + 1]);
        RequiredAudit::record(fn () => activity('agt_projection')->performedOn($submission)->causedByAnonymous()->event('agt.observation')
            ->withProperties(['actor_kind' => 'system', 'workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
                'environment' => $submission->environment, 'operator_kind' => $operatorKind, 'operator_unix_uid' => $operatorUid, 'user_agent' => null, 'real_actor_id' => null, 'effective_actor_id' => null, 'impersonation_session' => null, 'automation_id' => null, 'idempotency_reference' => null, 'ip_address' => null, 'operation_id' => $entry->operation_uuid, 'request_id' => $entry->correlation_id,
                'reducer_version' => 1, 'projection_revision' => $submission->projection_revision, 'outcome_category' => $projection['knowledge'], 'evidence_reference' => $entry->id])->log('AGT projection updated'));
    }
}
