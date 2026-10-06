<?php

namespace App\Fiscal\Agt\Exceptions;

use DomainException;

final class UnsupportedAgtSchema extends DomainException
{
    public static function assertSupported(string $version): void
    {
        if ($version !== '2.0') {
            throw new self('A AGT exige o contrato 2.0. Guarde novamente a configuração e o rascunho; documentos já emitidos não podem ser reescritos.');
        }
    }
}
