<?php

namespace App;

enum SubscriptionCouponDiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case FreeDays = 'free_days';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentagem',
            self::Fixed => 'Valor fixo',
            self::FreeDays => 'Tempo grátis',
        };
    }
}
