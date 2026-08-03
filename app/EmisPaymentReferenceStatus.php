<?php

namespace App;

enum EmisPaymentReferenceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pagamento pendente',
            self::Paid => 'Pagamento confirmado',
            self::Expired => 'Referência expirada',
            self::Cancelled => 'Referência cancelada',
            self::Failed => 'Falha no pagamento',
        };
    }
}
