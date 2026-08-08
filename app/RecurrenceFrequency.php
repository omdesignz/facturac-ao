<?php

namespace App;

use Carbon\CarbonInterface;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Fortnightly => 'Quinzenal',
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Biannual => 'Semestral',
            self::Annual => 'Anual',
        };
    }

    /**
     * The next occurrence after the given one.
     *
     * Month-based steps use Carbon's no-overflow arithmetic: a profile that
     * runs on the 31st should land on the 28th in February, not skip into
     * March and drift a day further every year.
     */
    public function next(CarbonInterface $from): CarbonInterface
    {
        return match ($this) {
            self::Weekly => $from->copy()->addWeek(),
            self::Fortnightly => $from->copy()->addWeeks(2),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Quarterly => $from->copy()->addMonthsNoOverflow(3),
            self::Biannual => $from->copy()->addMonthsNoOverflow(6),
            self::Annual => $from->copy()->addYearNoOverflow(),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
