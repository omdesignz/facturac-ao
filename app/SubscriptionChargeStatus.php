<?php

namespace App;

enum SubscriptionChargeStatus: string
{
    case Creating = 'creating';
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Creating => 'A criar referência',
            self::Pending => 'A aguardar pagamento',
            self::Paid => 'Pago',
            self::Expired => 'Expirado',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}
