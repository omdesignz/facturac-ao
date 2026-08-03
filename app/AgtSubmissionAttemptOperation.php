<?php

namespace App;

enum AgtSubmissionAttemptOperation: string
{
    case RegisterInvoice = 'register_invoice';
    case QueryStatus = 'query_status';

    public function label(): string
    {
        return match ($this) {
            self::RegisterInvoice => 'Registar factura',
            self::QueryStatus => 'Consultar estado',
        };
    }
}
