<?php

namespace App\Http\Controllers;

use App\AgtSubmissionStatus;
use App\Analytics\AnalyticsPeriod;
use App\Analytics\ReceivablesQuery;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtSubmission;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business and operational analytics, every figure shown against the equivalent
 * previous period.
 */
class AnalyticsController extends Controller
{
    public function __construct(private ReceivablesQuery $receivables) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace->legalEntities()->oldest('id')->first();

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        $period = AnalyticsPeriod::fromKey((string) $request->string('period', 'this_month'));

        $current = $this->scopeToPeriod($legalEntity, $period->start, $period->end);
        $previous = $this->scopeToPeriod($legalEntity, $period->previousStart, $period->previousEnd);

        return Inertia::render('Analytics/Index', [
            'period' => [
                'key' => $period->key,
                'label' => $period->label,
                'comparison_label' => $period->comparisonLabel,
                'start' => $period->start->toIso8601String(),
                'end' => $period->end->toIso8601String(),
            ],
            'periods' => AnalyticsPeriod::options(),
            'summary' => $this->receivables->summarise($current),
            'previousSummary' => $this->receivables->summarise($previous),
            'trend' => $this->trend($period, $current, $previous),
            'topCustomers' => $this->topCustomers($current),
            'typeMix' => $this->typeMix($current),
            'operations' => $this->operations($legalEntity, $period),
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }

    /**
     * @return Builder<FiscalDocument>
     */
    private function scopeToPeriod(
        LegalEntity $legalEntity,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Builder {
        return $this->receivables
            ->forLegalEntity($legalEntity)
            // Full instants, not date strings: document_date carries a time on
            // some drivers, and '2026-08-04 00:00:00' sorts after '2026-08-04',
            // which would silently drop the last day of every period.
            ->whereBetween('document_date', [$start, $end]);
    }

    /**
     * Revenue per bucket, with the previous period mapped onto the same axis.
     *
     * Both sides arrive already summed per day, so the work here is bounded by
     * the length of the period rather than by how many documents fall in it.
     *
     * @param  Builder<FiscalDocument>  $current
     * @param  Builder<FiscalDocument>  $previous
     * @return list<array{label: string, value: int, comparison: int}>
     */
    private function trend(
        AnalyticsPeriod $period,
        Builder $current,
        Builder $previous,
    ): array {
        $buckets = $period->buckets();
        $currentTotals = array_fill_keys(array_keys($buckets), 0);
        $previousTotals = $currentTotals;

        foreach ($this->receivables->dailyBilled($current) as $date => $total) {
            $key = $period->bucketKey(CarbonImmutable::parse($date));

            if (array_key_exists($key, $currentTotals)) {
                $currentTotals[$key] += $total;
            }
        }

        foreach ($this->receivables->dailyBilled($previous) as $date => $total) {
            $key = $period->comparisonBucketKey(CarbonImmutable::parse($date));

            if (array_key_exists($key, $previousTotals)) {
                $previousTotals[$key] += $total;
            }
        }

        $points = [];

        foreach ($buckets as $key => $label) {
            $points[] = [
                'label' => $label,
                'value' => $currentTotals[$key],
                'comparison' => $previousTotals[$key],
            ];
        }

        return $points;
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{label: string, value: int, detail: string}>
     */
    private function topCustomers(Builder $query): array
    {
        return array_map(
            fn (array $row): array => [
                'label' => $row['name'],
                'value' => $row['total_minor'],
                'detail' => $row['document_count'].' documento(s)',
            ],
            $this->receivables->topCustomers($query),
        );
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{label: string, value: int, detail: string, slot: int}>
     */
    private function typeMix(Builder $query): array
    {
        $totals = $this->receivables->totalsByType($query);
        $mix = [];
        $slot = 0;

        foreach (FiscalDocumentType::issuable() as $type) {
            $slot++;
            $entry = $totals[$type->value] ?? null;

            // A receipt carries no value of its own — it settles an invoice.
            // Plotting it at zero on a value chart invites the reader to think
            // the receipts were worth nothing rather than that they are not
            // measured here.
            if ($entry === null || $entry['total_minor'] <= 0) {
                continue;
            }

            $mix[] = [
                'label' => $type->label(),
                'value' => $entry['total_minor'],
                'detail' => $entry['document_count'].'×',
                'slot' => min(5, $slot),
            ];
        }

        return $mix;
    }

    /**
     * How the application itself is doing: whether documents are reaching the
     * AGT, how long that takes, and what is sitting unfinished.
     *
     * @return array<string, mixed>
     */
    private function operations(LegalEntity $legalEntity, AnalyticsPeriod $period): array
    {
        $submissions = AgtSubmission::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get(['status', 'created_at', 'updated_at']);

        $settled = $submissions->filter(
            fn (AgtSubmission $submission): bool => in_array($submission->status, [
                AgtSubmissionStatus::Valid,
                AgtSubmissionStatus::Invalid,
                AgtSubmissionStatus::Rejected,
            ], true),
        );

        $accepted = $submissions
            ->where('status', AgtSubmissionStatus::Valid)
            ->count();

        $failed = $submissions->filter(
            fn (AgtSubmission $submission): bool => in_array($submission->status, [
                AgtSubmissionStatus::Invalid,
                AgtSubmissionStatus::Rejected,
                AgtSubmissionStatus::Failed,
            ], true),
        )->count();

        $pending = $submissions->count() - $accepted - $failed;

        // Median rather than mean: one submission stuck behind an AGT outage
        // would drag an average to a number that describes nothing.
        $durations = $settled
            ->map(fn (AgtSubmission $submission): int => max(
                0,
                (int) $submission->created_at?->diffInSeconds($submission->updated_at),
            ))
            ->sort()
            ->values();

        $medianSeconds = $durations->isEmpty()
            ? null
            : (int) $durations[(int) floor(($durations->count() - 1) / 2)];

        $stock = StockLevel::query()
            ->with('catalogueItem')
            ->where('legal_entity_id', $legalEntity->id)
            ->get();

        return [
            'submissions_total' => $submissions->count(),
            'submissions_accepted' => $accepted,
            'submissions_failed' => $failed,
            'submissions_pending' => max(0, $pending),
            'acceptance_rate' => $submissions->count() === 0
                ? null
                : (int) round(($accepted / $submissions->count()) * 100),
            'median_seconds_to_settle' => $medianSeconds,
            'drafts_open' => FiscalDocument::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->where('status', FiscalDocumentStatus::Draft)
                ->count(),
            'active_customers' => Customer::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->where('is_active', true)
                ->count(),
            'tracked_items' => CatalogueItem::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->where('tracks_stock', true)
                ->count(),
            'stock_value_minor' => $stock->sum(
                fn (StockLevel $level): int => $level->valueMinor(),
            ),
            'stock_low_count' => $stock->filter(
                fn (StockLevel $level): bool => $level->isBelowReorderLevel(),
            )->count(),
        ];
    }
}
