<?php

namespace App;

enum AgtConnectionStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Configuração incompleta',
            self::Ready => 'Pronta para testar',
            self::Verified => 'Ligação verificada',
            self::Failed => 'Requer atenção',
        };
    }
}
