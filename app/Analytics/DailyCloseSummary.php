<?php

namespace App\Analytics;

use App\Models\LegalEntity;
use Carbon\CarbonImmutable;

/**
 * One company's day in three figures: what it sold, what it received, and what
 * customers still owe. Every figure comes from a query the app already shows
 * (Hoje's `DashboardQuery::kpis`, the customer page's `dailyPaid`), so the
 * evening push and the screens can never disagree.
 */
class DailyCloseSummary
{
    public function __construct(
        private DashboardQuery $dashboard,
        private ReceivablesQuery $receivables,
    ) {}

    /**
     * @return array{date: string, currency_code: string, billed_minor: int, issued_count: int, received_minor: int, outstanding_minor: int, overdue_minor: int, overdue_count: int}
     */
    public function for(LegalEntity $legalEntity, CarbonImmutable $day): array
    {
        $kpis = $this->dashboard->kpis($legalEntity, $day);

        return [
            'date' => $day->toDateString(),
            'currency_code' => $legalEntity->currency_code,
            'billed_minor' => $kpis['billed_today_minor'],
            'issued_count' => $kpis['issued_today'],
            'received_minor' => $this->receivedOn($legalEntity, $day),
            'outstanding_minor' => $kpis['outstanding_minor'],
            'overdue_minor' => $kpis['overdue_minor'],
            'overdue_count' => $kpis['overdue_count'],
        ];
    }

    /** Money in on the day: invoice-receipts and tickets when issued, receipts by payment date. */
    public function receivedOn(LegalEntity $legalEntity, CarbonImmutable $day): int
    {
        return $this->receivables->dailyPaid($this->receivables->forLegalEntity($legalEntity))[$day->toDateString()] ?? 0;
    }
}
