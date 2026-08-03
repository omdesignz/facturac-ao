<?php

namespace App;

enum WorkspaceSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A aguardar pagamento',
            self::Active => 'Activa',
            self::PastDue => 'Pagamento em atraso',
            self::Suspended => 'Suspensa',
            self::Cancelled => 'Cancelada',
        };
    }
}
