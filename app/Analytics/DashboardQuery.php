<?php

namespace App\Analytics;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
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
    public function __construct(
        private ReceivablesQuery $receivables,
        private AgingQuery $aging,
        private DocumentSnapshot $snapshot,
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
            'pending_today' => $countFor([...DocumentSnapshot::IN_FLIGHT, FiscalDocumentStatus::Contingency]),
            'invalid_today' => $countFor([FiscalDocumentStatus::Invalid]),
            'attention_count' => $all->clone()->whereIn('status', DocumentSnapshot::NEEDS_ATTENTION)->count(),
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
            'attention_count' => $documents->clone()->whereIn('status', DocumentSnapshot::NEEDS_ATTENTION)->count(),
            'in_flight_count' => $documents->clone()->whereIn('status', DocumentSnapshot::IN_FLIGHT)->count(),
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
        return ['reason' => $reason, ...$this->snapshot->describe($document, $now)];
    }
}
