<?php

namespace App\Analytics;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns issued documents into what is actually owed.
 *
 * The arithmetic lives here rather than in a controller because it is easy to
 * get subtly wrong and expensive to get wrong quietly: an invoice is reduced by
 * the credit notes that reference it and by the receipts that settle it, an
 * invoice-receipt (FR) is paid the moment it is issued, and a standalone receipt
 * is not a receivable at all — it is the payment.
 *
 * Every total is computed by the database, grouped, rather than by loading
 * documents into memory: a workspace issuing tens of thousands of documents a
 * month would otherwise pay for all of them to build one summary card.
 *
 * The SQL is deliberately plain — CASE expressions and plain aggregates, no
 * date functions — because the test suite runs on SQLite and production runs on
 * MySQL, and a query that only works on one of those proves nothing about the
 * other.
 */
class ReceivablesQuery
{
    /**
     * Document types that put money on the customer's account.
     *
     * @return list<string>
     */
    public static function billableTypes(): array
    {
        return array_values(array_map(
            fn (FiscalDocumentType $type): string => $type->value,
            array_filter(
                FiscalDocumentType::issuable(),
                fn (FiscalDocumentType $type): bool => $type->requiresLines()
                    && ! $type->reducesReceivable(),
            ),
        ));
    }

    /**
     * Billable types that can still be owed money.
     *
     * An invoice-receipt is invoiced and paid in the same act, so it is billable
     * but never outstanding.
     *
     * @return list<string>
     */
    public static function owableTypes(): array
    {
        return array_values(array_filter(
            self::billableTypes(),
            fn (string $type): bool => $type !== FiscalDocumentType::InvoiceReceipt->value,
        ));
    }

    /**
     * Issued documents only.
     *
     * A draft is not a receivable and not revenue; counting one would report
     * money that nobody has been asked for.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return Builder<FiscalDocument>
     */
    public static function issued(Builder $query): Builder
    {
        return $query->whereNot('status', FiscalDocumentStatus::Draft);
    }

    /**
     * @return Builder<FiscalDocument>
     */
    public function forLegalEntity(LegalEntity $legalEntity): Builder
    {
        return self::issued(
            FiscalDocument::query()->where('legal_entity_id', $legalEntity->id),
        );
    }

    /**
     * Attaches each document's settled and credited totals.
     *
     * Used when the documents themselves are being listed — a page of them at a
     * time. The aggregates below reuse this as their subquery rather than
     * duplicating the correlation.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return Builder<FiscalDocument>
     */
    public function withBalances(Builder $query): Builder
    {
        return $query
            ->withSum('settledBy as settled_minor', 'amount_minor')
            ->withSum(
                ['adjustments as credited_minor' => fn (Builder $nested) => self::issued($nested)
                    ->where('document_type', FiscalDocumentType::CreditNote)],
                'gross_total_minor',
            );
    }

    /**
     * Every figure a summary card shows, in one grouped query.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return array{billed_minor: int, credited_minor: int, paid_minor: int, outstanding_minor: int, overdue_minor: int, overdue_count: int, document_count: int, tax_minor: int, net_minor: int}
     */
    public function summarise(Builder $query): array
    {
        $billable = self::billableTypes();
        $credit = FiscalDocumentType::CreditNote->value;
        $outstanding = $this->outstandingExpression();

        $row = $this->aggregateOver($query)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type IN ('.$this->placeholders($billable).')'
                .' THEN d.gross_total_minor ELSE 0 END), 0) AS billed_minor',
                $billable,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type = ? THEN d.gross_total_minor ELSE 0 END), 0)'
                .' AS credited_minor',
                [$credit],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type = ? THEN d.gross_total_minor'
                .' WHEN d.document_type IN ('.$this->placeholders($billable).')'
                .' THEN COALESCE(d.settled_minor, 0) ELSE 0 END), 0) AS paid_minor',
                [FiscalDocumentType::InvoiceReceipt->value, ...$billable],
            )
            ->selectRaw("COALESCE(SUM({$outstanding}), 0) AS outstanding_gross", self::owableTypes())
            /*
             * A credit note tied to an invoice is already netted off that
             * invoice above. Only a standalone credit — one that references no
             * document — still has to come off the total, or it would be
             * subtracted twice.
             */
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type = ? AND d.references_document_id IS NULL'
                .' THEN d.gross_total_minor ELSE 0 END), 0) AS unapplied_credit_minor',
                [$credit],
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.due_date IS NOT NULL AND d.due_date < ? THEN {$outstanding} ELSE 0 END), 0)"
                .' AS overdue_minor',
                [$this->today(), ...self::owableTypes()],
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.due_date IS NOT NULL AND d.due_date < ? AND {$outstanding} > 0"
                .' THEN 1 ELSE 0 END), 0) AS overdue_count',
                [$this->today(), ...self::owableTypes()],
            )
            ->selectRaw('COUNT(*) AS document_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type = ? THEN -d.tax_payable_minor'
                .' WHEN d.document_type IN ('.$this->placeholders($billable).')'
                .' THEN d.tax_payable_minor ELSE 0 END), 0) AS tax_minor',
                [$credit, ...$billable],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN d.document_type = ? THEN -d.net_total_minor'
                .' WHEN d.document_type IN ('.$this->placeholders($billable).')'
                .' THEN d.net_total_minor ELSE 0 END), 0) AS net_minor',
                [$credit, ...$billable],
            )
            ->first();

        $values = (array) $row;

        return [
            'billed_minor' => (int) ($values['billed_minor'] ?? 0),
            'credited_minor' => (int) ($values['credited_minor'] ?? 0),
            'paid_minor' => (int) ($values['paid_minor'] ?? 0),
            'outstanding_minor' => max(0, (int) ($values['outstanding_gross'] ?? 0)
                - (int) ($values['unapplied_credit_minor'] ?? 0)),
            'overdue_minor' => (int) ($values['overdue_minor'] ?? 0),
            'overdue_count' => (int) ($values['overdue_count'] ?? 0),
            'document_count' => (int) ($values['document_count'] ?? 0),
            'tax_minor' => (int) ($values['tax_minor'] ?? 0),
            'net_minor' => (int) ($values['net_minor'] ?? 0),
        ];
    }

    /**
     * Billed value per calendar day, for the trend charts.
     *
     * Grouped by the raw date rather than by a formatted month, because the date
     * functions that would do the formatting are spelled differently on every
     * driver. Callers fold days into whatever bucket they plot; the result set
     * is bounded by the length of the period, not the document count.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return array<string, int>
     */
    public function dailyBilled(Builder $query): array
    {
        return $query->clone()
            ->whereIn('document_type', self::billableTypes())
            ->reorder()
            ->toBase()
            ->groupBy('document_date')
            ->selectRaw('document_date, COALESCE(SUM(gross_total_minor), 0) AS total_minor')
            ->pluck('total_minor', 'document_date')
            ->mapWithKeys(fn (mixed $total, mixed $date): array => [
                // Drivers hand a date column back as either a plain date or a
                // full timestamp; the first ten characters are the day either way.
                substr((string) $date, 0, 10) => (int) $total,
            ])
            ->all();
    }

    /**
     * The customers who were billed most, highest first.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{customer_id: int|null, name: string, total_minor: int, document_count: int}>
     */
    public function topCustomers(Builder $query, int $limit = 8): array
    {
        return array_values(array_map(
            fn (object $row): array => [
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'name' => (string) $row->customer_name,
                'total_minor' => (int) $row->total_minor,
                'document_count' => (int) $row->document_count,
            ],
            $query->clone()
                ->whereIn('document_type', self::billableTypes())
                ->reorder()
                ->toBase()
                ->groupBy('customer_id', 'customer_name')
                ->selectRaw('customer_id, customer_name')
                ->selectRaw('COALESCE(SUM(gross_total_minor), 0) AS total_minor')
                ->selectRaw('COUNT(*) AS document_count')
                ->orderByDesc('total_minor')
                ->limit($limit)
                ->get()
                ->all(),
        ));
    }

    /**
     * Value and count per document type.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return array<string, array{total_minor: int, document_count: int}>
     */
    public function totalsByType(Builder $query): array
    {
        return $query->clone()
            ->reorder()
            ->toBase()
            ->groupBy('document_type')
            ->selectRaw('document_type')
            ->selectRaw('COALESCE(SUM(gross_total_minor), 0) AS total_minor')
            ->selectRaw('COUNT(*) AS document_count')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->document_type => [
                    'total_minor' => (int) $row->total_minor,
                    'document_count' => (int) $row->document_count,
                ],
            ])
            ->all();
    }

    /**
     * How long, in days, settled invoices took to be paid in full.
     *
     * Only fully settled invoices count: a half-paid invoice has no answer yet,
     * and including it would drag the average toward whatever today happens to
     * be. An invoice-receipt is paid on issue, so it contributes a zero.
     *
     * The subtraction happens in PHP rather than SQL because date arithmetic is
     * spelled differently on every driver (DATEDIFF on MySQL, julianday on
     * SQLite) and this is two columns per settled invoice, not whole models.
     *
     * @param  Builder<FiscalDocument>  $query
     */
    public function averageDaysToSettle(Builder $query): ?int
    {
        $rows = $this->aggregateOver($query)
            ->leftJoinSub(
                DB::table('fiscal_document_settlements')
                    ->join(
                        'fiscal_documents as receipts',
                        'receipts.id',
                        '=',
                        'fiscal_document_settlements.fiscal_document_id',
                    )
                    ->groupBy('fiscal_document_settlements.settled_document_id')
                    ->selectRaw('fiscal_document_settlements.settled_document_id AS invoice_id')
                    ->selectRaw('MAX(receipts.document_date) AS settled_on'),
                'paid',
                'paid.invoice_id',
                '=',
                'd.id',
            )
            ->whereIn('d.document_type', self::billableTypes())
            ->selectRaw('d.document_type, d.document_date, paid.settled_on')
            ->selectRaw(
                'd.gross_total_minor - COALESCE(d.settled_minor, 0)'
                .' - COALESCE(d.credited_minor, 0) AS balance',
            )
            ->get();

        $spans = [];

        foreach ($rows as $row) {
            if ($row->document_type === FiscalDocumentType::InvoiceReceipt->value) {
                $spans[] = 0;

                continue;
            }

            if ((int) $row->balance > 0 || ! is_string($row->settled_on)) {
                continue;
            }

            $spans[] = max(0, (int) CarbonImmutable::parse((string) $row->document_date)
                ->diffInDays(CarbonImmutable::parse($row->settled_on)));
        }

        return $spans === [] ? null : (int) round(array_sum($spans) / count($spans));
    }

    /**
     * Wraps a document query as a subquery so aggregates can read the settled
     * and credited columns it computes.
     *
     * @param  Builder<FiscalDocument>  $query
     */
    private function aggregateOver(Builder $query): QueryBuilder
    {
        return DB::query()->fromSub(
            $this->withBalances($query->clone()->reorder()),
            'd',
        );
    }

    /**
     * What one document still owes, as SQL.
     *
     * Expects the owable types to be bound wherever it is interpolated.
     *
     * @return literal-string
     */
    private function outstandingExpression(): string
    {
        return 'CASE WHEN d.document_type IN ('.$this->placeholders(self::owableTypes()).')'
            .' THEN CASE WHEN d.gross_total_minor - COALESCE(d.settled_minor, 0)'
            .' - COALESCE(d.credited_minor, 0) > 0'
            .' THEN d.gross_total_minor - COALESCE(d.settled_minor, 0)'
            .' - COALESCE(d.credited_minor, 0) ELSE 0 END'
            .' ELSE 0 END';
    }

    /**
     * A literal placeholder list of the given width.
     *
     * Spelled as a match rather than built with implode so it stays a
     * literal-string: that is what lets the analyser prove no value is ever
     * interpolated into the SQL, which is the whole point of demanding one.
     *
     * @param  list<string>  $values
     * @return literal-string
     */
    private function placeholders(array $values): string
    {
        return match (count($values)) {
            1 => '?',
            2 => '?, ?',
            3 => '?, ?, ?',
            4 => '?, ?, ?, ?',
            5 => '?, ?, ?, ?, ?',
            default => throw new InvalidArgumentException(
                'Unexpected number of document types to bind.',
            ),
        };
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    /** Kept for the presentation layer, which reads the computed columns. */
    public function isBillable(FiscalDocumentType $type): bool
    {
        return in_array($type->value, self::billableTypes(), true);
    }

    public function outstandingMinor(FiscalDocument $document): int
    {
        if (! in_array($document->document_type->value, self::owableTypes(), true)) {
            return 0;
        }

        return max(0, $document->gross_total_minor
            - (int) ($document->getAttribute('settled_minor') ?? 0)
            - (int) ($document->getAttribute('credited_minor') ?? 0));
    }

    public function paidMinor(FiscalDocument $document): int
    {
        if ($document->document_type === FiscalDocumentType::InvoiceReceipt) {
            return $document->gross_total_minor;
        }

        return (int) ($document->getAttribute('settled_minor') ?? 0);
    }

    /** Past its due date with money still on it. */
    public function isOverdue(FiscalDocument $document): bool
    {
        return $document->due_date !== null
            && $document->due_date->isPast()
            && $this->outstandingMinor($document) > 0;
    }
}
