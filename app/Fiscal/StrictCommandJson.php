<?php

namespace App\Fiscal;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class StrictCommandJson
{
    /** @return array<string, mixed> */
    public static function object(#[\SensitiveParameter] string $json): array
    {
        try {
            $object = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400);
        }
        abort_unless($object instanceof \stdClass, 400);
        self::uniqueMembers($json);

        return get_object_vars($object);
    }

    private static function uniqueMembers(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]/s', $json, $tokens);
        $stack = [];
        foreach ($tokens[0] as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($tokens[0][$index + 1] ?? '') === ':' && $stack !== []) {
                $level = count($stack) - 1;
                $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                abort_if(isset($stack[$level]['keys'][$key]), 400);
                $stack[$level]['keys'][$key] = true;
            }
        }
    }
}
