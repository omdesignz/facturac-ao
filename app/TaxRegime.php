<?php

namespace App;

enum TaxRegime: string
{
    case General = 'general';
    case Simplified = 'simplified';
    case Exclusion = 'exclusion';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Regime geral',
            self::Simplified => 'Regime simplificado',
            self::Exclusion => 'Regime de exclusão',
        };
    }
}
