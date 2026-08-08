<?php

namespace App;

enum ComplaintCategory: string
{
    case Invoicing = 'invoicing';
    case AgtSubmission = 'agt_submission';
    case Billing = 'billing';
    case DataPrivacy = 'data_privacy';
    case Support = 'support';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoicing => 'Emissão de documentos',
            self::AgtSubmission => 'Comunicação à AGT',
            self::Billing => 'Cobrança e pagamentos',
            self::DataPrivacy => 'Dados pessoais e privacidade',
            self::Support => 'Atendimento',
            self::Other => 'Outro assunto',
        };
    }

    /**
     * A privacy complaint carries a legal answering deadline of its own, so it
     * is routed to the data protection contact rather than general support.
     */
    public function isDataProtection(): bool
    {
        return $this === self::DataPrivacy;
    }
}
