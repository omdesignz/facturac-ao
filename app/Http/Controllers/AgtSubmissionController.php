<?php

namespace App\Http\Controllers;

use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Documents\AgtStatusPresentation;
use App\Fiscal\Documents\CurrentAgtState;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionAttempt;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentEvent;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgtSubmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $legalEntity = $this->legalEntity($request);

        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('viewAny', FiscalDocument::class);
        Inertia::encryptHistory();

        $query = AgtSubmission::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id);
        $submissions = (clone $query)
            ->with('fiscalDocument')
            ->withCount('attempts')
            ->latest('created_at')
            ->limit(100)
            ->get();
        $selectedPublicId = $request->string('submission')->trim()->toString();
        $selected = $submissions->firstWhere('public_id', $selectedPublicId)
            ?? $submissions->first();

        if ($selected instanceof AgtSubmission) {
            $selected->load([
                'attempts' => fn ($attempts) => $attempts->reorder()->latest('attempt_number')->limit(25),
                'fiscalDocument.events',
                'fiscalDocument.fiscalSeries',
            ]);
        }

        $documents = FiscalDocument::query()->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)->whereHas('submissions');
        $summary = [];
        foreach ([...array_column(AgtSubmissionStatus::cases(), 'value'), 'unknown'] as $status) {
            $summary[$status] = CurrentAgtState::matching(clone $documents, [$status])->count();
        }

        return Inertia::render('Agt/Submissions/Index', [
            'summary' => $summary,
            'submissions' => $submissions
                ->map(fn (AgtSubmission $submission): array => $this->submissionProps($submission))
                ->values()
                ->all(),
            'selected' => $selected instanceof AgtSubmission
                ? $this->selectedProps($selected)
                : null,
            'permissions' => [
                'refresh' => $request->user()?->can('create', FiscalDocument::class) === true,
            ],
            'guardrails' => [
                'raw_payloads_exposed' => false,
                'maximum_visible_records' => 100,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function submissionProps(AgtSubmission $submission): array
    {
        $document = $submission->fiscalDocument;

        return [
            'public_id' => $submission->public_id,
            'status' => CurrentAgtState::status($document)->value,
            'status_label' => AgtStatusPresentation::label($document, CarbonImmutable::now()),
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->label(),
            'customer_name' => $document->customer_name,
            'gross_total' => (string) CanonicalNumber::fromMinorUnits($document->gross_total_minor),
            'currency_code' => $document->currency_code,
            'request_id' => $submission->request_id,
            'attempt_count' => $submission->attempts_count,
            'safe_message' => AgtStatusPresentation::describe($document, CarbonImmutable::now())['explanation']['message'],
            'agt_operational' => AgtStatusPresentation::describe($document, CarbonImmutable::now()),
            'created_at' => $submission->created_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function selectedProps(AgtSubmission $submission): array
    {
        $document = $submission->fiscalDocument;

        return [
            ...$this->submissionProps($submission),
            'submission_uuid' => $submission->submission_uuid,
            'schema_version' => $submission->schema_version,
            'request_body_sha256' => $submission->request_body_sha256,
            'last_response_body_sha256' => $submission->last_response_body_sha256,
            'last_http_status' => $submission->last_http_status,
            'last_result_code' => null,
            'last_error_codes' => [],
            'series_code' => $document->fiscalSeries?->series_code,
            'issue_sequence' => $document->issue_sequence,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'received_at' => $submission->received_at?->toIso8601String(),
            'completed_at' => $submission->completed_at?->toIso8601String(),
            'attempts' => $submission->attempts
                ->map(fn (AgtSubmissionAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'operation' => $attempt->operation->value,
                    'operation_label' => $attempt->operation->label(),
                    'attempt_number' => $attempt->attempt_number,
                    'http_status' => $attempt->http_status,
                    'result_code' => null,
                    'error_codes' => [],
                    'safe_message' => 'Registo histórico de comunicação; não comprova por si só validação.',
                    'request_body_sha256' => $attempt->request_body_sha256,
                    'response_body_sha256' => $attempt->response_body_sha256,
                    'started_at' => $attempt->started_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'events' => $document->events
                ->map(fn (FiscalDocumentEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->event_type->value,
                    'label' => $event->event_type->label(),
                    'agt_document_status' => $event->agt_document_status,
                    'safe_context' => [],
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }
}
