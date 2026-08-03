<?php

namespace App\Fiscal\Agt\Support;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function encode(array $payload): string
    {
        return $this->encodeValue($payload);
    }

    /**
     * @throws JsonException
     */
    private function encodeValue(mixed $value): string
    {
        if ($value instanceof CanonicalNumber) {
            return $value->value();
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Floating-point values are prohibited in canonical fiscal JSON.');
        }

        if (is_string($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Unsupported value in canonical fiscal JSON.');
        }

        if (array_is_list($value)) {
            return '['.implode(',', array_map(
                fn (mixed $item): string => $this->encodeValue($item),
                $value,
            )).']';
        }

        ksort($value, SORT_STRING);
        $properties = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Canonical JSON object keys must be strings.');
            }

            $encodedKey = json_encode(
                $key,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $properties[] = $encodedKey.':'.$this->encodeValue($item);
        }

        return '{'.implode(',', $properties).'}';
    }
}
