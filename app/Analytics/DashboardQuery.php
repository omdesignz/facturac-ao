<?php

namespace App\Analytics;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the dashboard shows, read from the same queries the analytics and
 * debts pages use, so the three can never disagree about a number.
 *
 * The page ranks one document above everything else. The order is the order of
 * urgency: a document the AGT marked invalid, then one waiting in contingency,
 * then the most overdue invoice, and on a quiet day simply the latest one.
 */
class DashboardQuery
{
    /** The statuses of a document the AGT has not finished with yet. */
    private const array IN_FLIGHT = [
        FiscalDocumentStatus::Issued,
        FiscalDocumentStatus::Received,
        FiscalDocumentStatus::Processing,
    ];

    /** Statuses that need someone to act, matching the register's "Requer atenção". */
    private const array NEEDS_ATTENTION = [
        FiscalDocumentStatus::Invalid,
        FiscalDocumentStatus::Contingency,
    ];

    public function __construct(
        private ReceivablesQuery $receivables,
        private AgingQuery $aging,
    ) {}

    /**
     * Today's figures, each against something a reader can check.
     *
     * Billed today is compared with the same weekday last week rather than with
     * yesterday: a Monday is not a Sunday, and that comparison would mislead
     * every week.
     *
     * @return array{billed_today_minor: int, billed_last_week_minor: int, issued_today: int, credit_notes_today: int, valid_today: int, pending_today: int, invalid_today: int, attention_count: int, outstanding_minor: int, overdue_minor: int, overdue_count: int}
     */
    public function kpis(LegalEntity $legalEntity, CarbonImmutable $now): array
    {
        $all = $this->receivables->forLegalEntity($legalEntity);
        $today = $this->onDay($all, $now);

        $statusCounts = $today->clone()
            ->reorder()
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        $countFor = fn (array $statuses): int => array_sum(array_map(
            fn (FiscalDocumentStatus $status): int => $statusCounts[$status->value] ?? 0,
            $statuses,
        ));

        $overall = $this->receivables->summarise($all->clone());

        return [
            'billed_today_minor' => $this->receivables->summarise($today->clone())['billed_minor'],
            'billed_last_week_minor' => $this->receivables->summarise($this->onDay($all, $now->subWeek()))['billed_minor'],
            'issued_today' => $statusCounts->sum(),
            'credit_notes_today' => $today->clone()
                ->where('document_type', FiscalDocumentType::CreditNote)
                ->count(),
            'valid_today' => $countFor([FiscalDocumentStatus::Valid]),
            'pending_today' => $countFor([...self::IN_FLIGHT, FiscalDocumentStatus::Contingency]),
            'invalid_today' => $countFor([FiscalDocumentStatus::Invalid]),
            'attention_count' => $all->clone()->whereIn('status', self::NEEDS_ATTENTION)->count(),
            'outstanding_minor' => $overall['outstanding_minor'],
            'overdue_minor' => $overall['overdue_minor'],
            'overdue_count' => $overall['overdue_count'],
        ];
    }

    /**
     * The one document the page leads with, or null before anything is issued.
     *
     * @return array<string, mixed>|null
     */
    public function focus(LegalEntity $legalEntity, CarbonImmutable $now): ?array
    {
        $issued = $this->receivables->forLegalEntity($legalEntity);

        $candidates = [
            'invalid' => fn (): ?FiscalDocument => $issued->clone()
                ->where('status', FiscalDocumentStatus::Invalid)
                ->latest('issued_at')
                ->latest('id')
                ->first(),
            'contingency' => fn (): ?FiscalDocument => $issued->clone()
                ->where('status', FiscalDocumentStatus::Contingency)
                ->latest('issued_at')
                ->latest('id')
                ->first(),
            'overdue' => fn (): ?FiscalDocument => $this->mostOverdue($legalEntity, $now),
            'latest' => fn (): ?FiscalDocument => $issued->clone()
                ->latest('issued_at')
                ->latest('id')
                ->first(),
        ];

        foreach ($candidates as $reason => $find) {
            $document = $find();

            if ($document instanceof FiscalDocument) {
                return $this->present($document, $reason, $now);
            }
        }

        return null;
    }

    /**
     * Standing problems, counted, so the review can say what needs doing.
     *
     * @return array{attention_count: int, in_flight_count: int, overdue_count: int, drafts_open: int}
     */
    public function review(LegalEntity $legalEntity, int $overdueCount): array
    {
        $documents = FiscalDocument::query()->where('legal_entity_id', $legalEntity->id);

        return [
            'attention_count' => $documents->clone()->whereIn('status', self::NEEDS_ATTENTION)->count(),
            'in_flight_count' => $documents->clone()->whereIn('status', self::IN_FLIGHT)->count(),
            'overdue_count' => $overdueCount,
            'drafts_open' => $documents->clone()->where('status', FiscalDocumentStatus::Draft)->count(),
        ];
    }

    /**
     * Who owes the most for the longest, and the age of all debt together.
     *
     * @return array{customers: list<array<string, mixed>>, customer_count: int, totals: list<array{key: string, label: string, total_minor: int}>, outstanding_minor: int}
     */
    public function collections(LegalEntity $legalEntity, int $limit = 4): array
    {
        $rows = $this->aging->byCustomer($legalEntity);

        return [
            'customers' => array_map(
                fn (array $row): array => [
                    'customer_public_id' => $row['customer_public_id'],
                    'name' => $row['name'],
                    'outstanding_minor' => $row['outstanding_minor'],
                    'overdue_minor' => $row['overdue_minor'],
                    'document_count' => $row['document_count'],
                    'oldest_days_past_due' => $row['oldest_days_past_due'],
                    'buckets' => $row['buckets'],
                ],
                array_slice($rows, 0, $limit),
            ),
            'customer_count' => count($rows),
            'totals' => $this->aging->totals($rows),
            'outstanding_minor' => array_sum(array_column($rows, 'outstanding_minor')),
        ];
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @return Builder<FiscalDocument>
     */
    private function onDay(Builder $query, CarbonImmutable $day): Builder
    {
        // Full instants rather than a date string, for the same reason the
        // analytics periods use them: document_date carries a time on some
        // drivers, and a bare date would drop the end of the day.
        return $query->clone()->whereBetween('document_date', [$day->startOfDay(), $day->endOfDay()]);
    }

    private function mostOverdue(LegalEntity $legalEntity, CarbonImmutable $now): ?FiscalDocument
    {
        // The oldest due dates first, a bounded handful: one of them is almost
        // always still owed, and scanning every past-due invoice to find it
        // would load the whole ledger for one card.
        return $this->receivables
            ->withBalances($this->receivables->forLegalEntity($legalEntity))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now->toDateString())
            ->orderBy('due_date')
            ->limit(25)
            ->get()
            ->first(fn (FiscalDocument $document): bool => $this->receivables->outstandingMinor($document) > 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FiscalDocument $document, string $reason, CarbonImmutable $now): array
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
            'reason' => $reason,
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'type_label' => $document->document_type->label(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
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
            'agt_message' => $this->needsExplaining($document) ? $submission?->safe_message : null,
            'agt_error_codes' => $this->needsExplaining($document) ? ($submission->last_error_codes ?? []) : [],
            'steps' => $this->steps($document, $submission, $outstanding, $daysPastDue),
        ];
    }

    private function needsExplaining(FiscalDocument $document): bool
    {
        return in_array($document->status, self::NEEDS_ATTENTION, true);
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
    ): array {
        $status = $document->status;
        $sentAt = $submission->submitted_at ?? $submission?->received_at;

        $agt = match (true) {
            $status === FiscalDocumentStatus::Valid => [
                'label' => 'Validada',
                'at' => $submission?->completed_at,
                'state' => 'done',
                'detail' => null,
            ],
            $status === FiscalDocumentStatus::Invalid => [
                'label' => 'Inválida',
                'at' => $submission->failed_at ?? $submission?->completed_at,
                'state' => 'error',
                'detail' => $submission?->safe_message,
            ],
            default => [
                'label' => 'Validada',
                'at' => null,
                'state' => in_array($status, self::IN_FLIGHT, true) ? 'current' : 'todo',
                'detail' => null,
            ],
        };

        $steps = [
            ['key' => 'draft', 'label' => 'Rascunho', 'at' => $document->created_at, 'state' => 'done', 'detail' => null],
            ['key' => 'issued', 'label' => 'Emitida', 'at' => $document->issued_at, 'state' => 'done', 'detail' => null],
            [
                'key' => 'sent',
                'label' => $status === FiscalDocumentStatus::Contingency ? 'Em contingência' : 'Enviada à AGT',
                'at' => $sentAt,
                'state' => match (true) {
                    $sentAt !== null => 'done',
                    $status === FiscalDocumentStatus::Contingency => 'current',
                    default => 'todo',
                },
                'detail' => null,
            ],
            ['key' => 'agt', ...$agt],
        ];

        // Only documents that put money on an account can be paid. A credit
        // note or a standalone receipt ends at validation.
        if ($this->receivables->isBillable($document->document_type)) {
            $paid = $document->document_type === FiscalDocumentType::InvoiceReceipt || $outstanding === 0;

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
}
