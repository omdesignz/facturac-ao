<?php

namespace App;

enum PaymentEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return $this === self::Sandbox ? 'Sandbox — sem movimento de dinheiro' : 'Produção';
    }
}
