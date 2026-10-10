<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionStatus;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\DocumentReadContext;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** A minimized disclosure of the existing qualified facade, never fiscal authority. */
final class QualifiedAgtStatusRead
{
    public const array FIELDS = ['document_public_id', 'knowledge', 'reported_state', 'synchronization', 'freshness', 'observed_at', 'last_successful_sync_at', 'as_of', 'effective_at', 'provenance', 'explanation_code', 'reconciliation_required'];

    public const array EXPLANATIONS = ['not_submitted', 'delivery_pending', 'delivery_acknowledged', 'processing_reported', 'validation_reported', 'invalidity_reported', 'processing_cancelled', 'request_failed', 'refresh_pending', 'sync_failed', 'stale_observation', 'unknown_response', 'evidence_conflict', 'legacy_unverified', 'state_unavailable'];

    public function snapshot(DocumentReadContext $context, DocumentReadCommand $command): FiscalDocument
    {
        $row = DB::table('fiscal_documents as d')->useWritePdo()->leftJoin('agt_submissions as s', function (JoinClause $join): void {
            $join->on('s.fiscal_document_id', '=', 'd.id')->on('s.workspace_id', '=', 'd.workspace_id')
                ->on('s.legal_entity_id', '=', 'd.legal_entity_id')->on('s.environment', '=', 'd.environment');
        })->where('d.workspace_id', $context->workspaceId())->where('d.legal_entity_id', $context->legalEntityId())
            ->where('d.environment', $context->environment()->value)->where('d.public_id', $command->publicId)
            ->select(['d.id', 'd.public_id', 'd.status', 'd.workspace_id', 'd.legal_entity_id', 'd.environment',
                's.id as submission_id', 's.status as submission_status', 's.reducer_version', 's.qualified_projection'])->first();
        abort_if($row === null, 404);
        $document = (new FiscalDocument)->newFromBuilder(Arr::only((array) $row, ['id', 'public_id', 'status', 'workspace_id', 'legal_entity_id', 'environment']));
        $submissions = (new AgtSubmission)->newCollection();
        if ($row->submission_id !== null && AgtSubmissionStatus::tryFrom($row->submission_status) !== null) {
            $submissions->push((new AgtSubmission)->newFromBuilder(['id' => $row->submission_id, 'status' => $row->submission_status,
                'reducer_version' => $row->reducer_version, 'qualified_projection' => $row->qualified_projection]));
        }
        $document->setRelation('submissions', $submissions);

        return $document;
    }

    public function databaseNow(): CarbonImmutable
    {
        $row = DB::connection()->selectOne(DB::getDriverName() === 'pgsql' ? 'SELECT clock_timestamp() AS observed_time' : 'SELECT CURRENT_TIMESTAMP AS observed_time', [], false);

        return CarbonImmutable::parse($row->observed_time, 'UTC');
    }

    /** @return array<string, mixed> */
    public function present(FiscalDocument $document, CarbonImmutable $asOf): array
    {
        $asOf = $asOf->utc()->startOfSecond();
        $base = ['document_public_id' => strtolower($document->public_id), 'knowledge' => 'unknown', 'reported_state' => null,
            'synchronization' => 'unknown', 'freshness' => 'unverified', 'observed_at' => null, 'last_successful_sync_at' => null,
            'as_of' => $asOf->toIso8601String(), 'effective_at' => null, 'provenance' => 'unverified',
            'explanation_code' => 'state_unavailable', 'reconciliation_required' => true];
        if ($document->isMutable()) {
            return $this->validated([...$base, 'knowledge' => 'not_applicable', 'synchronization' => 'not_applicable',
                'freshness' => 'not_applicable', 'provenance' => 'not_applicable', 'explanation_code' => 'not_submitted', 'reconciliation_required' => false]);
        }
        $submission = $document->submissions->first();
        $raw = $submission?->qualified_projection;
        if ($submission?->reducer_version !== 1 || ! AgtObservationReducer::validProjection($raw)) {
            return $this->validated($base);
        }
        foreach (['observed_at', 'last_successful_sync_at'] as $key) {
            if ($raw[$key] !== null && CarbonImmutable::parse($raw[$key])->gt($asOf)) {
                return $this->validated($base);
            }
        }
        $state = CurrentAgtState::projection($submission, $asOf);
        $presentation = AgtStatusPresentation::describe($document, $asOf);
        $code = $presentation['explanation']['code'];
        if (! in_array($code, self::EXPLANATIONS, true) || ! in_array($state['sync'], ['idle', 'pending', 'failed'], true)
            || ! in_array($presentation['freshness'], ['not_applicable', 'unverified', 'recent_observation', 'stale'], true)) {
            return $this->validated($base);
        }
        $data = [...$base, 'synchronization' => $state['sync'], 'last_successful_sync_at' => $state['last_successful_sync_at'], 'explanation_code' => $code];
        if ($state['classification'] === 'conflicting' || $state['knowledge'] === 'conflicting_evidence') {
            $data = [...$data, 'knowledge' => 'conflicting_evidence', 'explanation_code' => 'evidence_conflict'];
        } elseif ($state['knowledge'] === 'legacy_unverified' || ($state['classification'] === 'partial' && ! ($state['knowledge'] === 'never_known' && $state['sync'] !== 'failed'
                && in_array($state['operational_status'], ['pending', 'sending', 'received'], true)))) {
            $data = [...$data, 'knowledge' => 'insufficient_evidence', 'explanation_code' => 'legacy_unverified'];
        } elseif ($state['knowledge'] === 'known' && $state['classification'] === 'authoritative' && in_array($state['operational_status'], ['valid', 'invalid', 'processing', 'cancelled'], true)) {
            $data = [...$data, 'knowledge' => 'known', 'reported_state' => $state['operational_status'] === 'cancelled' ? 'processing_cancelled' : $state['operational_status'],
                'provenance' => 'agt_observation', 'freshness' => $presentation['freshness'], 'observed_at' => $state['observed_at'], 'reconciliation_required' => false];
        } elseif ($state['knowledge'] === 'never_known' && $state['sync'] !== 'failed' && in_array($state['operational_status'], ['pending', 'sending', 'received'], true)) {
            $data = [...$data, 'provenance' => 'workflow_only', 'reconciliation_required' => false];
        } elseif ($state['sync'] === 'pending') {
            $data = [...$data, 'explanation_code' => 'refresh_pending', 'reconciliation_required' => false];
        }

        return $this->validated($data);
    }

    /** Complete validation precedes required success audit and credential last-use.
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        if (array_keys($data) !== self::FIELDS || ! is_string($data['document_public_id']) || ! Str::isUlid($data['document_public_id'])) {
            throw new LogicException('Invalid qualified read representation.');
        }
        $enums = ['knowledge' => ['not_applicable', 'known', 'unknown', 'insufficient_evidence', 'conflicting_evidence'],
            'reported_state' => [null, 'valid', 'invalid', 'processing', 'processing_cancelled'],
            'synchronization' => ['not_applicable', 'idle', 'pending', 'failed', 'unknown'],
            'freshness' => ['not_applicable', 'unverified', 'recent_observation', 'stale'],
            'provenance' => ['not_applicable', 'agt_observation', 'workflow_only', 'unverified'], 'explanation_code' => self::EXPLANATIONS];
        foreach ($enums as $key => $values) {
            if (! in_array($data[$key], $values, true)) {
                throw new LogicException('Invalid qualified read representation.');
            }
        }
        foreach (['observed_at', 'last_successful_sync_at', 'as_of'] as $key) {
            if ($data[$key] !== null && (! is_string($data[$key]) || CarbonImmutable::parse($data[$key])->utc()->toIso8601String() !== $data[$key]
                || $data[$key] > $data['as_of'])) {
                throw new LogicException('Invalid qualified read timestamp.');
            }
        }
        $known = $data['knowledge'] === 'known';
        if ($data['as_of'] === null || $data['effective_at'] !== null || ! is_bool($data['reconciliation_required'])
            || ($known && ($data['reported_state'] === null || $data['observed_at'] === null || $data['provenance'] !== 'agt_observation'
                || $data['reconciliation_required'] || ! in_array($data['freshness'], ['recent_observation', 'stale'], true)))
            || (! $known && ($data['reported_state'] !== null || $data['observed_at'] !== null))
            || (in_array($data['reported_state'], ['valid', 'invalid', 'processing_cancelled'], true) && $data['synchronization'] !== 'idle')) {
            throw new LogicException('Invalid qualified read combination.');
        }
        json_encode($data, JSON_THROW_ON_ERROR);

        return $data;
    }
}
