<?php

namespace App\Analytics;

use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;

/**
 * How old the money owed is, per customer.
 *
 * Aging is the difference between "we are owed a lot" and "we are owed a lot
 * from March", which is the only version anyone can act on. Buckets are counted
 * from the due date, not the issue date: a 90-day invoice issued in January is
 * not late in February.
 */
class AgingQuery
{
    /**
     * Bucket boundaries in days past due, and what to call each one.
     *
     * @return list<array{key: string, label: string, from: int, to: int|null}>
     */
    public static function buckets(): array
    {
        return [
            ['key' => 'current', 'label' => 'Por vencer', 'from' => -100000, 'to' => 0],
            ['key' => 'd1_30', 'label' => '1–30 dias', 'from' => 1, 'to' => 30],
            ['key' => 'd31_60', 'label' => '31–60 dias', 'from' => 31, 'to' => 60],
            ['key' => 'd61_90', 'label' => '61–90 dias', 'from' => 61, 'to' => 90],
            ['key' => 'd90_plus', 'label' => 'Mais de 90 dias', 'from' => 91, 'to' => null],
        ];
    }

    public function __construct(private ReceivablesQuery $receivables) {}

    /**
     * Every customer with money outstanding, oldest debt first.
     *
     * @return list<array<string, mixed>>
     */
    public function byCustomer(LegalEntity $legalEntity): array
    {
        $documents = $this->receivables
            ->withBalances($this->receivables->forLegalEntity($legalEntity))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->with('customer:id,public_id,name,credit_limit_minor,payment_terms_days')
            ->get();

        $rows = [];

        foreach ($documents as $document) {
            $outstanding = $this->receivables->outstandingMinor($document);

            if ($outstanding <= 0) {
                continue;
            }

            $key = $document->customer_id ?? 0;

            $rows[$key] ??= [
                'customer_public_id' => $document->customer?->public_id,
                'name' => $document->customer_name,
                'credit_limit_minor' => $document->customer?->credit_limit_minor,
                'payment_terms_days' => $document->customer->payment_terms_days ?? 0,
                'outstanding_minor' => 0,
                'overdue_minor' => 0,
                'document_count' => 0,
                'oldest_days_past_due' => 0,
                'buckets' => array_fill_keys(
                    array_column(self::buckets(), 'key'),
                    0,
                ),
            ];

            $daysPastDue = $this->daysPastDue($document);
            $bucket = $this->bucketFor($daysPastDue);

            $rows[$key]['outstanding_minor'] += $outstanding;
            $rows[$key]['buckets'][$bucket] += $outstanding;
            $rows[$key]['document_count']++;

            if ($daysPastDue > 0) {
                $rows[$key]['overdue_minor'] += $outstanding;
                $rows[$key]['oldest_days_past_due'] = max(
                    $rows[$key]['oldest_days_past_due'],
                    $daysPastDue,
                );
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['over_limit'] = $row['credit_limit_minor'] !== null
                && $row['outstanding_minor'] > $row['credit_limit_minor'];
        }

        // Oldest debt first: that is the order anyone chasing money works in.
        uasort($rows, fn (array $a, array $b): int => [
            $b['oldest_days_past_due'], $b['outstanding_minor'],
        ] <=> [$a['oldest_days_past_due'], $a['outstanding_minor']]);

        return array_values($rows);
    }

    /**
     * The totals per bucket across every customer.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, total_minor: int}>
     */
    public function totals(array $rows): array
    {
        return array_map(
            function (array $bucket) use ($rows): array {
                $total = 0;

                foreach ($rows as $row) {
                    $total += $row['buckets'][$bucket['key']] ?? 0;
                }

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'total_minor' => $total,
                ];
            },
            self::buckets(),
        );
    }

    /**
     * A running statement for one customer: every movement and the balance
     * after it, oldest first, the way a conta corrente is read.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return list<array<string, mixed>>
     */
    public function statement(Builder $query): array
    {
        $documents = $this->receivables
            ->withBalances($query)
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();

        $balance = 0;
        $rows = [];

        foreach ($documents as $document) {
            $type = $document->document_type;
            $movement = 0;

            if ($this->receivables->isBillable($type)) {
                $movement = $document->gross_total_minor;
            } elseif ($type->reducesReceivable()) {
                $movement = -$document->gross_total_minor;
            } elseif ($type->settlesOtherDocuments()) {
                // A receipt is what the customer paid, so it moves the balance
                // down by the amount it settled rather than by its own total.
                $movement = -(int) $document->settlements()->sum('amount_minor');
            }

            if ($movement === 0 && ! $this->receivables->isBillable($type)) {
                continue;
            }

            $balance += $movement;

            $rows[] = [
                'public_id' => $document->public_id,
                'document_no' => $document->document_no,
                'document_type_label' => $type->label(),
                'document_date' => $document->document_date->toIso8601String(),
                'due_date' => $document->due_date?->toIso8601String(),
                'movement_minor' => $movement,
                'balance_minor' => $balance,
                'days_past_due' => $this->daysPastDue($document),
            ];
        }

        return $rows;
    }

    /** Negative until the due date passes; zero when there is no due date. */
    private function daysPastDue(FiscalDocument $document): int
    {
        if ($document->due_date === null) {
            return 0;
        }

        return (int) $document->due_date->startOfDay()->diffInDays(
            now('Africa/Luanda')->startOfDay(),
            false,
        );
    }

    private function bucketFor(int $daysPastDue): string
    {
        foreach (self::buckets() as $bucket) {
            if ($daysPastDue >= $bucket['from']
                && ($bucket['to'] === null || $daysPastDue <= $bucket['to'])) {
                return $bucket['key'];
            }
        }

        return 'current';
    }

    /** @return Builder<FiscalDocument> */
    public function forCustomer(Customer $customer): Builder
    {
        return ReceivablesQuery::issued($customer->fiscalDocuments()->getQuery());
    }
}
