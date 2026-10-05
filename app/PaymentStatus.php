<?php

namespace App;

enum PaymentStatus: string
{
    case Created = 'created';
    case Creating = 'creating';
    case Pending = 'pending';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Created, self::Creating => 'A preparar pagamento',
            self::Pending => 'A aguardar confirmação',
            self::Paid => 'Pago',
            self::Rejected => 'Rejeitado',
            self::Review => 'Confirmação por verificar',
        };
    }
}
