<?php

namespace App;

enum LegalEntityStatus: string
{
    case Draft = 'draft';
    case Configured = 'configured';
    case Homologation = 'homologation';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Configured => 'Perfil configurado',
            self::Homologation => 'Em homologação',
            self::Active => 'Activa',
            self::Suspended => 'Suspensa',
        };
    }
}
