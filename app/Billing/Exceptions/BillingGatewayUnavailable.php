<?php

namespace App\Billing\Exceptions;

use RuntimeException;

final class BillingGatewayUnavailable extends RuntimeException
{
    public static function pay4AllContractRequired(): self
    {
        return new self(
            'A integração Pay4All aguarda as credenciais e a especificação API assinada do comerciante.',
        );
    }
}
