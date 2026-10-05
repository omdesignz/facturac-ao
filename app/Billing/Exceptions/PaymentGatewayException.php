<?php

namespace App\Billing\Exceptions;

use RuntimeException;

final class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $outcomeUnknown = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($outcomeUnknown
            ? 'O pedido de pagamento precisa de verificação. Não inicie outro pagamento nem faça transferências manuais.'
            : 'O pagamento não foi iniciado. Verifique a configuração ou tente novamente mais tarde.');
    }

    /** @return array<string, int|string|bool|null> */
    public function context(): array
    {
        return ['failure_code' => $this->failureCode, 'outcome_unknown' => $this->outcomeUnknown];
    }
}
