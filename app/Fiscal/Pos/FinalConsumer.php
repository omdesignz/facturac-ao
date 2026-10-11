<?php

namespace App\Fiscal\Pos;

/**
 * The buyer on a sale where nobody gave a name or a tax number.
 *
 * One definition, because the till, the receipt and the server all have to
 * agree on who "Consumidor final" is: a document that says it in one place and
 * something else in another is a document the AGT will not match.
 */
final class FinalConsumer
{
    public const NAME = 'Consumidor final';

    public const TAX_IDENTIFICATION_NUMBER = '999999999';

    public const COUNTRY_CODE = 'AO';

    /**
     * The customer block of a fiscal document profile.
     *
     * @return array{name: string, tax_identification_number: string, country_code: string, address_line: string|null}
     */
    public static function profile(): array
    {
        return [
            'name' => self::NAME,
            'tax_identification_number' => self::TAX_IDENTIFICATION_NUMBER,
            'country_code' => self::COUNTRY_CODE,
            'address_line' => null,
        ];
    }

    /**
     * What the till is told, so it can show the same buyer without guessing.
     *
     * @return array{name: string, tax_identification_number: string, country_code: string}
     */
    public static function props(): array
    {
        return [
            'name' => self::NAME,
            'tax_identification_number' => self::TAX_IDENTIFICATION_NUMBER,
            'country_code' => self::COUNTRY_CODE,
        ];
    }
}
