<?php

namespace App\Fiscal\Documents;

/**
 * Why a document cannot be corrected with a credit note made from its own lines.
 *
 * The phone and the web follow the same rules and only say them differently:
 * what the phone hands over to the web, the web hands over to a note written
 * by hand.
 */
enum CreditNoteObstacle: string
{
    case NotCorrectable = 'not_correctable';
    case Returned = 'returned';
    case NotValidated = 'not_validated';
    case ForeignCurrencyOrWithholdings = 'foreign_currency_or_withholdings';
    case FullyCredited = 'fully_credited';

    /**
     * What the web document panel says instead of offering the correction.
     */
    public function webMessage(): string
    {
        return match ($this) {
            self::NotCorrectable => 'Só se corrigem assim facturas e facturas-recibo.',
            self::Returned => 'A AGT devolveu este documento. Trate a devolução antes de o corrigir.',
            self::NotValidated => 'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.',
            self::ForeignCurrencyOrWithholdings => 'Este documento tem moeda estrangeira ou retenções. Faça a nota de crédito à mão, em Novo documento.',
            self::FullyCredited => 'Este documento já foi creditado por inteiro.',
        };
    }
}
