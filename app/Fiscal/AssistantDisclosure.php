<?php

namespace App\Fiscal;

use Normalizer;

/** Local pattern policy; not a guarantee that arbitrary prose contains no sensitive data. */
final class AssistantDisclosure
{
    /** @param list<array{kind: string, public_id: string}> $references */
    public static function question(#[\SensitiveParameter] string $question, #[\SensitiveParameter] array $references): string
    {
        abort_if(! mb_check_encoding($question, 'UTF-8') || mb_strlen($question) > 2000 || strlen($question) > 8192
            || preg_match('/(?!\n)[\p{Cc}\p{Cf}]/u', $question) !== 0, 503);
        $parts = [$question];
        $aliased = $question;
        foreach ($references as $index => $reference) {
            $pattern = '/'.preg_quote($reference['public_id'], '/').'/i';
            $aliased = preg_replace($pattern, 'r'.($index + 1), $aliased);
            abort_unless(is_string($aliased), 503);
            $next = [];
            foreach ($parts as $part) {
                $segments = preg_split($pattern, $part);
                abort_unless(is_array($segments), 503);
                array_push($next, ...$segments);
            }
            $parts = $next;
        }
        foreach ($parts as $part) {
            self::check($part);
        }

        return $aliased;
    }

    private static function check(#[\SensitiveParameter] string $text): void
    {
        $normalized = Normalizer::normalize(mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'), Normalizer::FORM_D);
        abort_unless(is_string($normalized), 503);
        $normalized = preg_replace('/\p{Mn}/u', '', $normalized);
        abort_unless(is_string($normalized), 503);
        abort_if(preg_match('/[0-7][0-9a-hjkmnp-tv-z]{25}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $normalized) === 1, 503);
        abort_if(preg_match('/[@`{}\p{Sc}]|[a-z][a-z0-9+.-]*:\/\/|www\.|-----begin|\bsk[-_]|\[(?:at|dot)\]|\((?:at|dot)\)/u', $normalized) === 1, 503);
        $words = ['aoa', 'kz', 'usd', 'eur', 'gbp', 'zar', 'brl', 'cny', 'jpy', 'chf', 'nif', 'nuit', 'iban', 'swift', 'password', 'senha', 'segredo', 'token', 'api_key', 'telefone', 'telemovel', 'email', 'e-mail', 'morada', 'endereco', 'rua', 'avenida', 'travessa', 'apartamento', 'postal', 'submission_id', 'request_id', 'agt_payload'];
        abort_if(preg_match('/(?<![\p{L}\p{N}_])(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $words)).')(?![\p{L}\p{N}_])/u', $normalized) === 1, 503);
        abort_if(preg_match('/\bnumero\s+(?:de documento|da factura(?:-recibo)?|da fatura)\b/u', $normalized) === 1, 503);
        $withoutDates = preg_replace('/(?<![\p{L}\p{N}_-])(?:(?!0000)[0-9]{4}-(?:0[1-9]|1[0-2])|(?:19|20)[0-9]{2})(?![\p{L}\p{N}_-])/u', '', $normalized);
        abort_unless(is_string($withoutDates), 503);
        abort_if(preg_match('/\p{N}/u', $withoutDates) !== 0, 503);
    }
}
