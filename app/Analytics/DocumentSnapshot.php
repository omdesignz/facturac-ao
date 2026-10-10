<?php

namespace App\Analytics;

use App\Fiscal\Documents\AgtStatusPresentation;
use App\Fiscal\Documents\CurrentAgtState;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentEvent;
use Carbon\CarbonImmutable;

/**
 * A single document as a person reads it: who it is for, what it is worth,
 * what is still owed, how far it has got with the AGT, and what happened to
 * it along the way.
 *
 * Shared by the dashboard's focus card and the register's detail panel, so the
 * two can never describe the same document differently.
 */
class DocumentSnapshot
{
    /** The statuses of a document the AGT has not finished with yet. */
    public const array IN_FLIGHT = [
        FiscalDocumentStatus::Issued,
        FiscalDocumentStatus::Received,
        FiscalDocumentStatus::Processing,
    ];

    /** Statuses that need someone to act, matching the register's "Requer atenção". */
    public const array NEEDS_ATTENTION = [
        FiscalDocumentStatus::Invalid,
        FiscalDocumentStatus::Contingency,
    ];

    public function __construct(private ReceivablesQuery $receivables) {}

    /**
     * One document's facts, balance and lifecycle, as the dashboard and the
     * register's detail panel both show it.
     *
     * @return array<string, mixed>
     */
    public function describe(FiscalDocument $document, CarbonImmutable $now): array
    {
        $document = $this->receivables
            ->withBalances(FiscalDocument::query()->whereKey($document->id))
            ->with(['establishment:id,name', 'issuer:id,name'])
            ->withCount('lines')
            ->firstOrFail();

        $submission = $document->submissions()->latest('id')->first();
        $outstanding = $this->receivables->outstandingMinor($document);
        $daysPastDue = $document->due_date !== null && $outstanding > 0
            ? max(0, (int) $document->due_date->startOfDay()->diffInDays($now->startOfDay(), false))
            : 0;

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'type_label' => $document->document_type->label(),
            'status' => CurrentAgtState::status($document, $now)->value,
            'status_label' => AgtStatusPresentation::label($document, $now),
            'customer_name' => $document->customer_name,
            'customer_tax_identification_number' => $document->customer_tax_identification_number,
            'establishment_name' => $document->establishment->name ?? null,
            'issuer_name' => $document->issuer->name ?? null,
            'lines_count' => (int) $document->getAttribute('lines_count'),
            'gross_total_minor' => $document->gross_total_minor,
            'tax_payable_minor' => $document->tax_payable_minor,
            'outstanding_minor' => $outstanding,
            'currency_code' => $document->currency_code,
            'document_date' => $document->document_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'days_past_due' => $daysPastDue,
            'agt_message' => AgtStatusPresentation::describe($document, $now)['explanation']['message'],
            'agt_operational' => AgtStatusPresentation::describe($document, $now),
            'agt_error_codes' => [],
            'steps' => $this->steps($document, $submission, $outstanding, $daysPastDue, $now),
        ];
    }

    /**
     * The document's life so far, as the dashboard's lifecycle track draws it.
     *
     * Every timestamp is one the record actually carries; a stage it has not
     * reached is left without one rather than guessed.
     *
     * @return list<array{key: string, label: string, at: string|null, state: string, detail: string|null}>
     */
    private function steps(
        FiscalDocument $document,
        ?AgtSubmission $submission,
        int $outstanding,
        int $daysPastDue,
        CarbonImmutable $asOf,
    ): array {
        $status = CurrentAgtState::status($document, $asOf)->value;
        $view = AgtStatusPresentation::describe($document, $asOf);
        $observedAt = $view['observed_at'] === null ? null : CarbonImmutable::parse($view['observed_at']);
        $sentAt = $submission->submitted_at ?? $submission?->received_at;

        $agt = match (true) {
            $status === 'valid' => [
                'label' => AgtStatusPresentation::label($document, $asOf),
                'at' => $observedAt,
                'state' => 'done',
                'detail' => null,
            ],
            in_array($status, CurrentAgtState::ATTENTION, true) => [
                'label' => AgtStatusPresentation::label($document, $asOf),
                'at' => $observedAt,
                'state' => 'error',
                'detail' => $view['explanation']['message'],
            ],
            default => [
                'label' => 'Validada',
                'at' => null,
                'state' => in_array($status, CurrentAgtState::ACTIVE, true) ? 'current' : 'todo',
                'detail' => null,
            ],
        };

        $steps = [
            [
                'key' => 'draft',
                'label' => 'Rascunho',
                'at' => $document->created_at,
                'state' => $status === 'draft' ? 'current' : 'done',
                'detail' => null,
            ],
            [
                'key' => 'issued',
                'label' => 'Emitida',
                'at' => $document->issued_at,
                'state' => $document->issued_at !== null ? 'done' : 'todo',
                'detail' => null,
            ],
            [
                'key' => 'sent',
                'label' => $status === 'contingency' ? 'Em contingência' : 'Enviada à AGT',
                'at' => $sentAt,
                'state' => match (true) {
                    $sentAt !== null => 'done',
                    $status === 'contingency' => 'current',
                    default => 'todo',
                },
                'detail' => null,
            ],
            ['key' => 'agt', ...$agt],
        ];

        // Only documents that put money on an account can be paid. A credit
        // note or a standalone receipt ends at validation.
        if ($this->receivables->isBillable($document->document_type)) {
            // A draft has asked nobody for money, so it cannot be paid yet.
            $paid = $status !== 'draft'
                && ($document->document_type === FiscalDocumentType::InvoiceReceipt || $outstanding === 0);

            $steps[] = [
                'key' => 'paid',
                'label' => 'Paga',
                'at' => $document->document_type === FiscalDocumentType::InvoiceReceipt ? $document->issued_at : null,
                'state' => match (true) {
                    $paid => 'done',
                    $daysPastDue > 0 => 'error',
                    default => 'todo',
                },
                'detail' => match (true) {
                    $paid => null,
                    $daysPastDue > 0 => 'Vencida há '.$daysPastDue.' '.($daysPastDue === 1 ? 'dia' : 'dias'),
                    $document->due_date !== null => 'Vence a '.$document->due_date->format('d/m'),
                    default => null,
                },
            ];
        }

        return array_map(
            fn (array $step): array => [
                ...$step,
                'at' => $step['at']?->toIso8601String(),
            ],
            $steps,
        );
    }

    /**
     * What happened to the document, newest first, in the words of the event
     * log. The actor is named where a person did it; the AGT's steps have none.
     *
     * @return list<array{type: string, label: string, tone: string, actor_name: string|null, document_no: string|null, occurred_at: string}>
     */
    public function history(FiscalDocument $document): array
    {
        return array_values($document->events()
            ->with('actor:id,name')
            // The relation reads oldest first, as evidence is written; a
            // person reads what happened last first.
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (FiscalDocumentEvent $event): array => [
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'tone' => match ($event->event_type->value) {
                    'validated' => 'done',
                    'invalidated', 'submission_rejected', 'delivery_failed', 'processing_cancelled' => 'error',
                    'issued' => 'done',
                    default => 'pending',
                },
                'actor_name' => $event->actor->name ?? null,
                'document_no' => is_string($event->safe_context['document_no'] ?? null)
                    ? $event->safe_context['document_no']
                    : null,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all());
    }
}
