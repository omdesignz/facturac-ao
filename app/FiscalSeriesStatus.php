<?php

namespace App;

enum FiscalSeriesStatus: string
{
    case Open = 'A';
    case InUse = 'U';
    case Closed = 'F';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::InUse => 'Em utilização',
            self::Closed => 'Fechada',
        };
    }

    public function canAllocate(): bool
    {
        return in_array($this, [self::Open, self::InUse], true);
    }
}
