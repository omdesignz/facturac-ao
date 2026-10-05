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
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Creating => 'A preparar pagamento',
            self::Pending => 'A aguardar pagamento',
            self::Paid => 'Pago',
            self::Expired => 'Expirado',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
            self::Review => 'Confirmação por verificar',
        };
    }
}
