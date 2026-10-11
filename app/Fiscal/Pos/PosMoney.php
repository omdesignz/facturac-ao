<?php

namespace App\Fiscal\Pos;

/**
 * Typed amounts to minor units, without ever passing through a float.
 */
final class PosMoney
{
    /** Up to two decimals, no leading zeros, no sign. */
    public const PATTERN = '/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/';

    /** Reads "1234.5" as 123450. The caller has already checked the pattern. */
    public static function toMinor(string $amount): int
    {
        $parts = explode('.', trim($amount));

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    /** Accepts the comma a Portuguese keyboard types. */
    public static function normalise(mixed $value): mixed
    {
        return is_string($value) ? str_replace(',', '.', trim($value)) : $value;
    }
}
