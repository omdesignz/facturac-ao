<?php

namespace App;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Sent => 'Enviado',
            self::Accepted => 'Aceite',
            self::Rejected => 'Recusado',
            self::Expired => 'Expirado',
            self::Converted => 'Facturado',
        };
    }

    /** Still open with the customer, so the total can still change. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    /**
     * A quote can become an invoice once the customer has said yes — and only
     * once, or the same work would be billed twice.
     */
    public function canConvert(): bool
    {
        return in_array($this, [self::Sent, self::Accepted], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Expired, self::Converted], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Draft, self::Sent, self::Accepted];
    }
}
