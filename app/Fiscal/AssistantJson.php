<?php

namespace App\Fiscal;

final class AssistantJson
{
    /** @return array<string, mixed> */
    public static function object(#[\SensitiveParameter] string $json, int $maximumBytes, int $status): array
    {
        abort_if(strlen($json) > $maximumBytes || ! mb_check_encoding($json, 'UTF-8'), $status);
        try {
            $object = json_decode($json, false, 6, JSON_THROW_ON_ERROR);
            abort_unless($object instanceof \stdClass, $status);
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]/s', $json, $tokens);
            $stack = [];
            foreach ($tokens[0] as $index => $token) {
                if ($token === '{' || $token === '[') {
                    $stack[] = [];
                } elseif ($token === '}' || $token === ']') {
                    array_pop($stack);
                } elseif (str_starts_with($token, '"') && ($tokens[0][$index + 1] ?? '') === ':' && $stack !== []) {
                    $level = count($stack) - 1;
                    $key = json_decode($token, true, 6, JSON_THROW_ON_ERROR);
                    abort_if(isset($stack[$level][$key]), $status);
                    $stack[$level][$key] = true;
                }
            }

            return get_object_vars($object);
        } catch (\JsonException) {
            abort($status);
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $keys
     */
    public static function keys(array $data, array $keys, int $status): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        abort_unless($actual === $keys, $status);
    }

    public static function publicId(mixed $id, int $status): string
    {
        abort_unless(is_string($id) && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}\z/', $id) === 1, $status);

        return strtolower($id);
    }
}
