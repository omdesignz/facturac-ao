<?php

namespace App;

enum PosCashMovementType: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Reforço',
            self::Out => 'Retirada',
        };
    }
}
