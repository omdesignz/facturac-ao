<?php

namespace App;

enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Annual => 'Anual',
        };
    }
}
