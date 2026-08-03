<?php

namespace App;

enum FiscalDocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Received = 'received';
    case Processing = 'processing';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Contingency = 'contingency';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Issued => 'Emitido',
            self::Received => 'Recebido pela AGT',
            self::Processing => 'Em validação',
            self::Valid => 'Válido',
            self::Invalid => 'Inválido',
            self::Contingency => 'Contingência',
        };
    }

    public function isMutable(): bool
    {
        return $this === self::Draft;
    }
}
