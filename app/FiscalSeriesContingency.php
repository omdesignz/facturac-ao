<?php

namespace App;

enum FiscalSeriesContingency: string
{
    case Normal = 'N';
    case Contingency = 'C';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Emissão normal',
            self::Contingency => 'Contingência AGT',
        };
    }
}
