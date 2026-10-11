<?php

namespace App;

use Carbon\CarbonImmutable;

enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Annual => 'Anual',
        };
    }

    /** The label that follows a price: «25 000 Kz por trimestre». */
    public function periodLabel(): string
    {
        return match ($this) {
            self::Monthly => 'por mês',
            self::Quarterly => 'por trimestre',
            self::Semiannual => 'por semestre',
            self::Annual => 'por ano',
        };
    }

    /** The number of calendar months one period covers. */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
        };
    }

    /**
     * Where a period that starts at the given moment ends.
     *
     * The single place a period end is computed: a charge stores the result
     * and the fulfilment of a payment recognises the interval again by
     * comparing against it, so the two must never drift apart.
     */
    public function advance(CarbonImmutable $from): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $from->addMonthNoOverflow(),
            self::Quarterly => $from->addMonthsNoOverflow(3),
            self::Semiannual => $from->addMonthsNoOverflow(6),
            self::Annual => $from->addYear(),
        };
    }

    /**
     * Every interval, shortest first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Monthly, self::Quarterly, self::Semiannual, self::Annual];
    }

    /** The position of this interval in the ordered list, starting at zero. */
    public function position(): int
    {
        return (int) array_search($this, self::ordered(), true);
    }
}
