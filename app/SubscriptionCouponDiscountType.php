<?php

namespace App;

enum SubscriptionCouponDiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentagem',
            self::Fixed => 'Valor fixo',
        };
    }
}
