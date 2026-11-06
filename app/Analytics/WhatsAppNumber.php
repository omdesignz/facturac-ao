<?php

namespace App\Analytics;

/**
 * A customer's phone as WhatsApp wants it: the full international number in
 * digits only, with no plus and no leading zeros.
 *
 * Numbers are kept as people typed them. An Angolan mobile written the local way
 * (nine digits starting with 9) is given the 244 it implies; any other number is
 * trusted only when it was written in international form, because guessing a
 * country for it would open a chat with a stranger.
 */
final class WhatsAppNumber
{
    public static function from(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $written = trim($phone);
        $digits = preg_replace('/\D+/', '', $written) ?? '';
        $international = str_starts_with($written, '+') || str_starts_with($digits, '00');
        $digits = ltrim($digits, '0');

        if (preg_match('/\A9\d{8}\z/', $digits) === 1) {
            return '244'.$digits;
        }

        if (preg_match('/\A2449\d{8}\z/', $digits) === 1) {
            return $digits;
        }

        if ($international && ! str_starts_with($digits, '244') && preg_match('/\A[1-9]\d{7,14}\z/', $digits) === 1) {
            return $digits;
        }

        return null;
    }
}
