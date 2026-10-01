<?php

namespace App;

enum TransportDocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Issued => 'Emitido',
            self::Cancelled => 'Anulado',
        };
    }

    public function movementStatus(): string
    {
        return $this === self::Cancelled ? 'A' : 'N';
    }

    public function isMutable(): bool
    {
        return $this === self::Draft;
    }
}
