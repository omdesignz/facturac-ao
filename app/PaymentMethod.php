<?php

namespace App;

/**
 * Payment mechanisms recognised by the AGT (SAF-T AO "MeioPagamento").
 *
 * The codes are the ones transmitted; the labels are what an Angolan user
 * recognises, with Multicaixa named explicitly since it is the common case.
 */
enum PaymentMethod: string
{
    case Cash = 'NU';
    case BankCheque = 'CH';
    case DebitCard = 'CD';
    case CreditCard = 'CC';
    case GiftCheque = 'CO';
    case BankTransfer = 'TB';
    case DirectDebit = 'DE';
    case Compensation = 'CS';
    case Letter = 'LC';
    case Other = 'OU';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Numerário',
            self::BankCheque => 'Cheque',
            self::DebitCard => 'Multicaixa / cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::GiftCheque => 'Cheque-oferta',
            self::BankTransfer => 'Transferência bancária',
            self::DirectDebit => 'Débito directo',
            self::Compensation => 'Compensação de saldos',
            self::Letter => 'Letra comercial',
            self::Other => 'Outro meio',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
            ],
            self::cases(),
        );
    }
}
