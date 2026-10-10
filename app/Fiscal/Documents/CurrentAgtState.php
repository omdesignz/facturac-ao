<?php

namespace App\Fiscal\Documents;

use App\AgtOperationalStatus;
use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\FiscalDocumentStatus;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class CurrentAgtState
{
    public const array ACTIVE = ['pending', 'sending', 'retrying', 'received', 'processing', 'issued'];

    public const array ATTENTION = ['invalid', 'rejected', 'cancelled', 'failed', 'contingency', 'unknown'];

    public static function legacyWorkflowStatus(FiscalDocument $document): AgtSubmissionStatus|FiscalDocumentStatus
    {
        if ($document->isMutable()) {
            return FiscalDocumentStatus::Draft;
        }

        $document->loadMissing('submissions');

        $submission = $document->submissions->first();

        return $submission->status ?? $document->status;
    }

    public static function status(FiscalDocument $document, ?CarbonImmutable $asOf = null): AgtSubmissionStatus|FiscalDocumentStatus|AgtOperationalStatus
    {
        if ($document->isMutable()) {
            return FiscalDocumentStatus::Draft;
        }
        $document->loadMissing('submissions');
        $state = self::projection($document->submissions->first(), $asOf);

        return AgtSubmissionStatus::tryFrom($state['operational_status']) ?? AgtOperationalStatus::Unknown;
    }

    /** @return array<string, mixed> */
    public static function projection(?AgtSubmission $submission, ?CarbonImmutable $asOf = null): array
    {
        $state = $submission?->qualified_projection;
        if ($submission?->reducer_version !== 1 || ! AgtObservationReducer::validProjection($state)) {
            return [...AgtObservationReducer::empty(), 'operational_status' => 'unknown'];
        }
        if ($state['operational_status'] !== 'unknown' && $state['operational_status'] !== $submission->status->value) {
            return [...AgtObservationReducer::empty(), 'knowledge' => 'legacy_unverified', 'reason' => 'legacy_unverified', 'operational_status' => 'unknown'];
        }

        $asOf ??= CarbonImmutable::now('UTC');
        if ($state['observed_at'] !== null && CarbonImmutable::parse($state['observed_at'])->gt($asOf)) {
            return [...$state, 'classification' => 'unknown', 'knowledge' => 'unknown_response', 'reported_state' => null,
                'reason' => 'unknown_response', 'operational_status' => 'unknown'];
        }

        return $state;
    }

    /** @param Builder<FiscalDocument> $query
     * @param  list<string>  $statuses
     * @return Builder<FiscalDocument>
     */
    public static function matching(Builder $query, array $statuses, ?CarbonImmutable $asOf = null): Builder
    {
        $instant = ($asOf ?? CarbonImmutable::now('UTC'))->utc()->toIso8601String();

        return $query->where(function (Builder $state) use ($statuses, $instant): void {
            if (in_array('draft', $statuses, true)) {
                $state->orWhere('status', FiscalDocumentStatus::Draft);
            }
            $state->orWhere(function (Builder $issued) use ($statuses, $instant): void {
                $issued->where('status', '!=', FiscalDocumentStatus::Draft)->whereHas('submissions', function (Builder $submission) use ($statuses, $instant): void {
                    $submission->where(function (Builder $qualified) use ($statuses, $instant): void {
                        $qualified->where('reducer_version', 1)->whereIn('qualified_projection->operational_status', $statuses)
                            ->where(function (Builder $consistent): void {
                                $consistent->where('qualified_projection->operational_status', 'unknown')
                                    ->orWhereColumn('status', 'qualified_projection->operational_status');
                            })->where(fn (Builder $observed) => $observed->whereNull('qualified_projection->observed_at')->orWhere('qualified_projection->observed_at', '<=', $instant));

                        if (in_array('unknown', $statuses, true)) {
                            $qualified->orWhereNull('qualified_projection')
                                ->orWhereColumn('status', '!=', 'qualified_projection->operational_status')
                                ->orWhere('qualified_projection->observed_at', '>', $instant);
                        }
                    });
                });
            });
            if (in_array('unknown', $statuses, true)) {
                $state->orWhere(fn (Builder $legacy) => $legacy->where('status', '!=', FiscalDocumentStatus::Draft)->whereDoesntHave('submissions'));
            }
        });
    }

    public static function validated(FiscalDocument $document): bool
    {
        return self::acceptanceEvidenceSatisfied($document);
    }

    public static function acceptanceEvidenceSatisfied(FiscalDocument $document): bool
    {
        if ($document->isMutable() || $document->environment === 'unresolved') {
            return false;
        }
        $submission = $document->submissions()->useWritePdo()->first();
        if ($submission === null || $submission->status !== AgtSubmissionStatus::Valid || $submission->active_operation_uuid !== null || $submission->next_attempt_at !== null) {
            return false;
        }
        $state = $submission->qualified_projection;
        if (! is_array($state) || $submission->reducer_version !== 1 || ($state['version'] ?? null) !== 1
            || ($state['classification'] ?? null) !== 'authoritative' || ($state['knowledge'] ?? null) !== 'known'
            || ($state['reported_state'] ?? null) !== 'valid' || ($state['sync'] ?? null) !== 'idle') {
            return false;
        }
        $entry = AgtSubmissionObservation::query()->useWritePdo()->whereKey($state['observation_id'] ?? null)
            ->where('agt_submission_id', $submission->id)->where('workspace_id', $document->workspace_id)->where('legal_entity_id', $document->legal_entity_id)->first();

        if ($entry === null || $entry->observed_at === null || $entry->observed_at->gt(AgtSubmissionExecution::databaseNow())
            || $state !== AgtObservationReducer::rebuild($submission)) {
            return false;
        }
        $attempt = $submission->attempts()->useWritePdo()->whereKey($entry->attempt_id)->first();
        if ($attempt === null || $attempt->workspace_id !== $document->workspace_id || $attempt->legal_entity_id !== $document->legal_entity_id
            || $attempt->operation !== AgtSubmissionAttemptOperation::QueryStatus || $attempt->attempt_number !== $entry->operation_sequence) {
            return false;
        }
        try {
            $evidence = AgtEvidence::fromAttempt($submission->loadMissing(['legalEntity', 'fiscalDocument']), $attempt);
        } catch (\Throwable) {
            return false;
        }

        return ($evidence['classification'] ?? null) === 'authoritative' && ($evidence['reported_state'] ?? null) === 'valid' && in_array($entry->kind, ['result', 'legacy_import'], true)
            && ($entry->outcome['classification'] ?? null) === 'authoritative'
            && ($entry->outcome['reported_state'] ?? null) === 'valid'
            && ($entry->outcome['eligible_generation'] ?? false) === true && $entry->attempt_id !== null
            && $entry->operation_sequence === $submission->operation_sequence
            && hash_equals($entry->outcome_sha256, hash('sha256', app(CanonicalJson::class)->encode($entry->outcome)));
    }

    /**
     * V1 compatibility summary only. Neither workflow nor frozen fallback is
     * qualified AGT evidence or fiscal authorization.
     *
     * @param  Builder<FiscalDocument>  $query
     * @param  list<string>  $statuses
     * @return Builder<FiscalDocument>
     */
    public static function legacyMatching(Builder $query, array $statuses): Builder
    {
        return $query->where(function (Builder $state) use ($statuses): void {
            $state->where(function (Builder $submitted) use ($statuses): void {
                $submitted->where('status', '!=', FiscalDocumentStatus::Draft)
                    ->whereHas('submissions', fn (Builder $submission) => $submission->whereIn('status', $statuses));
            })->orWhere(function (Builder $legacy) use ($statuses): void {
                $legacy->whereDoesntHave('submissions')->whereIn('status', $statuses);
            });

            if (in_array('draft', $statuses, true)) {
                $state->orWhere('status', FiscalDocumentStatus::Draft);
            }
        });
    }
}
