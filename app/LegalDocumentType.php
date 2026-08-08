<?php

namespace App;

enum LegalDocumentType: string
{
    case Privacy = 'privacy';
    case Terms = 'terms';
    case Cookies = 'cookies';

    public function label(): string
    {
        return match ($this) {
            self::Privacy => 'Política de Privacidade',
            self::Terms => 'Termos e Condições',
            self::Cookies => 'Política de Cookies',
        };
    }

    /** The public path this document is published at. */
    public function slug(): string
    {
        return match ($this) {
            self::Privacy => 'privacidade',
            self::Terms => 'termos',
            self::Cookies => 'cookies',
        };
    }

    /**
     * Accepting the terms is what binds a customer to the contract, so only
     * that one is put in front of them for an explicit answer.
     */
    public function requiresAcceptance(): bool
    {
        return $this === self::Terms;
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return [self::Terms, self::Privacy, self::Cookies];
    }
}
