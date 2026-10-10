<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionStatus;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AgtReconstruction
{
    /** Maintenance import never rewrites workflow or frozen/append-only records. */
    public static function rebuild(int $id, ?int $operatorUid = null, ?string $operatorKind = null): void
    {
        DB::transaction(function () use ($id, $operatorUid, $operatorKind): void {
            $submission = AgtSubmissionExecution::locked($id, allowQuarantine: true);
            if ($submission === null) {
                return;
            }
            $entries = AgtSubmissionObservation::query()->where('agt_submission_id', $id)->exists();
            if ($entries) {
                $state = AgtObservationReducer::rebuild($submission);
                if ($submission->qualified_projection !== $state) {
                    $latest = AgtSubmissionObservation::query()->where('agt_submission_id', $id)->orderByDesc('id')->firstOrFail();
                    AgtSubmissionExecution::project($submission, $latest, $operatorUid, $operatorKind);
                }
                self::reserveLegacyRecovery($submission, $operatorUid, $operatorKind);

                return;
            }
            $state = AgtObservationReducer::empty();
            $state['knowledge'] = 'legacy_unverified';
            $state['reason'] = 'legacy_unverified';
            $previous = null;
            $expected = 1;
            $lastAttempt = null;
            $terminal = null;
            $classification = 'unknown';
            $lastEntry = null;
            $historyIncomplete = false;
            $historyConflicting = false;
            foreach ($submission->attempts()->orderBy('attempt_number')->get() as $attempt) {
                $lastAttempt = $attempt->id;
                if ($submission->environment === 'unresolved') {
                    $historyIncomplete = true;

                    continue;
                }
                $ordered = ! ($attempt->started_at->lt($submission->created_at) || ($submission->fiscalDocument->issued_at !== null && $attempt->started_at->lt($submission->fiscalDocument->issued_at))
                    || $attempt->attempt_number !== $expected || $attempt->completed_at === null || $attempt->completed_at->lt($attempt->started_at) || $attempt->completed_at->gt(AgtSubmissionExecution::databaseNow())
                    || ($previous !== null && $attempt->started_at->lt($previous)));
                $expected++;
                $historyIncomplete = $historyIncomplete || ! $ordered;
                $previous = $attempt->completed_at ?? $previous;
                try {
                    $outcome = AgtEvidence::fromAttempt($submission, $attempt);
                } catch (Throwable) {
                    $historyIncomplete = true;

                    continue;
                }
                if ($outcome['knowledge'] === 'legacy_unverified') {
                    $historyIncomplete = true;

                    continue;
                }
                $historyConflicting = $historyConflicting || $outcome['classification'] === 'conflicting';
                $reported = $outcome['reported_state'];
                if (in_array($reported, ['valid', 'invalid'], true)) {
                    if ($terminal !== null && $terminal !== $reported) {
                        $historyConflicting = true;
                    }
                    $terminal = $reported;
                }
                $state = [...$state, ...$outcome];
                if (! $historyIncomplete && ! $historyConflicting) {
                    $lastEntry = AgtSubmissionExecution::append($submission, (string) Str::uuid(), $attempt->attempt_number,
                        'legacy_import', [...$outcome, 'eligible_generation' => true], AgtSubmissionExecution::databaseNow(),
                        $attempt->completed_at->utc(), $attempt->id);
                }
                $classification = $outcome['classification'];
            }
            if ($historyIncomplete) {
                $classification = 'partial';
            }
            if ($historyConflicting) {
                $classification = 'conflicting';
            }
            $sequence = max($submission->attempt_count, (int) $submission->attempts()->max('attempt_number'));
            if ($expected - 1 !== $sequence || ($state['reported_state'] === 'valid' && $submission->status->value !== 'valid')
                || ($state['reported_state'] === 'invalid' && $submission->status->value !== 'invalid')) {
                $classification = in_array($classification, ['authoritative', 'conflicting'], true) ? 'conflicting' : 'partial';
            }
            if ($classification !== 'authoritative') {
                $state['reported_state'] = null;
                $state['successful_sync'] = false;
                $state['knowledge'] = $classification === 'conflicting' ? 'conflicting_evidence' : 'legacy_unverified';
                $state['reason'] = $classification === 'conflicting' ? 'evidence_conflict' : 'legacy_unverified';
            }
            $state['classification'] = $classification;
            $state['eligible_generation'] = true;
            $now = AgtSubmissionExecution::databaseNow();
            $entry = $lastEntry;
            if ($entry === null || $classification !== 'authoritative') {
                $outcome = array_intersect_key($state, array_flip(['version', 'classification', 'knowledge', 'reported_state', 'sync', 'delivery_state', 'reason', 'successful_sync', 'eligible_generation']));
                $entry = AgtSubmissionExecution::append($submission, (string) Str::uuid(), $sequence + ($lastEntry !== null ? 1 : 0), 'legacy_import', $outcome,
                    $now, null, $lastAttempt);
                $sequence = $entry->operation_sequence;
            }
            $submission->update(['operation_sequence' => $sequence]);
            AgtSubmissionExecution::project($submission, $entry, $operatorUid, $operatorKind);
            self::reserveLegacyRecovery($submission, $operatorUid, $operatorKind);
        }, 3);
    }

    /** Reserve a new local fence for paused pre-fence Sending work, never a historical network claim. */
    private static function reserveLegacyRecovery(AgtSubmission $submission, ?int $operatorUid, ?string $operatorKind): void
    {
        if ($submission->status !== AgtSubmissionStatus::Sending || filled($submission->request_id)
            || $submission->active_operation_uuid !== null
            || ! in_array($submission->qualified_projection['classification'] ?? null, ['unknown', 'partial'], true)
            || AgtSubmissionObservation::query()->where('agt_submission_id', $submission->id)->where('kind', 'claim')->exists()) {
            return;
        }
        $now = AgtSubmissionExecution::databaseNow();
        $uuid = (string) Str::uuid();
        $sequence = $submission->operation_sequence + 1;
        $entry = AgtSubmissionExecution::append($submission, $uuid, $sequence, 'claim', [
            'version' => 1, 'operation' => 'register_invoice', 'execution_uuid' => $uuid, 'queue_attempt' => 1,
            'request_identity_sha256' => hash('sha256', ''), 'frozen_request_sha256' => $submission->request_body_sha256,
        ], $now, null);
        $submission->update(['operation_sequence' => $sequence, 'active_operation_uuid' => $uuid, 'operation_lease_expires_at' => $now->addMinutes(2)]);
        AgtSubmissionExecution::project($submission, $entry, $operatorUid, $operatorKind);
    }
}
