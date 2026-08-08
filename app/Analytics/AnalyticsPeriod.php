<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A window of time and the equivalent window before it.
 *
 * Every figure on the analytics page is shown against a comparison, and the
 * comparison is only honest if the two windows are the same shape: this month
 * against last month, this year against last year — never against "the previous
 * 30 days" when the current window is a calendar month of 28.
 */
class AnalyticsPeriod
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $comparisonLabel,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly string $granularity,
    ) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => 'this_month', 'label' => 'Este mês'],
            ['value' => 'last_month', 'label' => 'Mês passado'],
            ['value' => 'this_quarter', 'label' => 'Este trimestre'],
            ['value' => 'this_year', 'label' => 'Este ano'],
            ['value' => 'last_12_months', 'label' => 'Últimos 12 meses'],
        ];
    }

    public static function fromKey(string $key): self
    {
        $now = CarbonImmutable::now('Africa/Luanda');

        return match ($key) {
            'last_month' => self::monthly($now->subMonth()),
            'this_quarter' => self::spanning(
                key: 'this_quarter',
                label: 'Este trimestre',
                comparisonLabel: 'Trimestre anterior',
                start: $now->firstOfQuarter()->startOfDay(),
                end: $now->endOfDay(),
                previousStart: $now->subQuarter()->firstOfQuarter()->startOfDay(),
                previousEnd: $now->subQuarter()->lastOfQuarter()->endOfDay(),
                granularity: 'month',
            ),
            'this_year' => self::spanning(
                key: 'this_year',
                label: 'Este ano',
                comparisonLabel: 'Ano anterior',
                start: $now->startOfYear(),
                end: $now->endOfDay(),
                previousStart: $now->subYear()->startOfYear(),
                previousEnd: $now->subYear()->endOfYear(),
                granularity: 'month',
            ),
            'last_12_months' => self::spanning(
                key: 'last_12_months',
                label: 'Últimos 12 meses',
                comparisonLabel: '12 meses anteriores',
                start: $now->subMonths(11)->startOfMonth(),
                end: $now->endOfDay(),
                previousStart: $now->subMonths(23)->startOfMonth(),
                previousEnd: $now->subMonths(12)->endOfMonth(),
                granularity: 'month',
            ),
            default => self::monthly($now, key: 'this_month', label: 'Este mês'),
        };
    }

    private static function monthly(
        CarbonImmutable $anchor,
        string $key = 'last_month',
        string $label = 'Mês passado',
    ): self {
        return self::spanning(
            key: $key,
            label: $label,
            comparisonLabel: 'Mês anterior',
            start: $anchor->startOfMonth(),
            end: $key === 'this_month' ? CarbonImmutable::now('Africa/Luanda')->endOfDay() : $anchor->endOfMonth(),
            previousStart: $anchor->subMonth()->startOfMonth(),
            previousEnd: $anchor->subMonth()->endOfMonth(),
            granularity: 'day',
        );
    }

    private static function spanning(
        string $key,
        string $label,
        string $comparisonLabel,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
        string $granularity,
    ): self {
        return new self(
            $key,
            $label,
            $comparisonLabel,
            $start,
            $end,
            $previousStart,
            $previousEnd,
            $granularity,
        );
    }

    /**
     * The buckets the trend chart plots, as [key => label].
     *
     * Built from the calendar rather than from the data, so a month with no
     * invoices shows as a gap at zero instead of silently disappearing and
     * making the line lie about its shape.
     *
     * @return array<string, string>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = $this->granularity === 'day'
            ? $this->start->startOfDay()
            : $this->start->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->end)) {
            $buckets[$this->bucketKey($cursor)] = $this->granularity === 'day'
                ? $cursor->format('j')
                : $cursor->translatedFormat('M');

            $cursor = $this->granularity === 'day'
                ? $cursor->addDay()
                : $cursor->addMonth();
        }

        return $buckets;
    }

    public function bucketKey(CarbonInterface $moment): string
    {
        return $this->granularity === 'day'
            ? $moment->format('Y-m-d')
            : $moment->format('Y-m');
    }

    /**
     * Maps a moment in the previous window onto the matching bucket of the
     * current one, so the two series line up point for point on the same axis.
     */
    public function comparisonBucketKey(CarbonInterface $moment): string
    {
        $offsetMonths = $this->previousStart->diffInMonths($this->start);

        return $this->bucketKey($this->granularity === 'day'
            ? $moment->copy()->addDays((int) $this->previousStart->diffInDays($this->start))
            : $moment->copy()->addMonths((int) $offsetMonths));
    }
}
