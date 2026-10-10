<?php

namespace App\Exceptions;

class ReceiptEvidenceUnavailable extends FiscalFinalizationBlocked
{
    public function __construct(public readonly string $environment)
    {
        parent::__construct('A validação AGT do documento de origem não está comprovada. Reveja a evidência antes de emitir o recibo.');
    }
}
