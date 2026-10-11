<?php

namespace App\Fiscal\Pos;

use App\PaymentMethod;

/**
 * The ways a till takes money.
 *
 * A subset of what the AGT recognises: a cheque or a letter of credit cannot be
 * settled across a counter, so they are not offered.
 */
final class PosPaymentMethods
{
    /** @return list<PaymentMethod> In the order the till shows them. */
    public static function all(): array
    {
        return [
            PaymentMethod::Cash,
            PaymentMethod::DebitCard,
            PaymentMethod::CreditCard,
            PaymentMethod::BankTransfer,
            PaymentMethod::Other,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (PaymentMethod $method): string => $method->value, self::all());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
            self::all(),
        );
    }
}
