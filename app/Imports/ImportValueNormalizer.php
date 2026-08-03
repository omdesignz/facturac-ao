<?php

namespace App\Imports;

use DomainException;
use Illuminate\Support\Str;

class ImportValueNormalizer
{
    public function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    public function nullableString(mixed $value): ?string
    {
        $normalized = $this->string($value);

        return $normalized === '' ? null : $normalized;
    }

    public function code(mixed $value): string
    {
        return Str::upper($this->string($value));
    }

    public function nullableCode(mixed $value): ?string
    {
        $normalized = $this->code($value);

        return $normalized === '' ? null : $normalized;
    }

    public function decimal(mixed $value): string
    {
        $decimal = str_replace(["\u{00A0}", ' '], '', $this->string($value));

        if ($decimal === '') {
            return '';
        }

        $lastComma = strrpos($decimal, ',');
        $lastDot = strrpos($decimal, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                return str_replace(',', '.', str_replace('.', '', $decimal));
            }

            return str_replace(',', '', $decimal);
        }

        return str_replace(',', '.', $decimal);
    }

    public function boolean(mixed $value, bool $default = true): bool|string
    {
        if ($value === null || $this->string($value) === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = Str::lower(Str::ascii($this->string($value)));

        if (in_array($normalized, ['1', 'sim', 'yes', 'true', 'activo', 'ativa', 'active'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'nao', 'no', 'false', 'inactivo', 'inativa', 'inactive'], true)) {
            return false;
        }

        return $this->string($value);
    }

    public function catalogueType(mixed $value): string
    {
        $type = Str::lower(Str::ascii($this->string($value)));

        return match ($type) {
            'produto', 'product', 'p' => 'product',
            'servico', 'service', 's' => 'service',
            default => $type,
        };
    }

    public function moneyToMinorUnits(string $amount): int
    {
        if (preg_match('/\A(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?\z/', $amount) !== 1) {
            throw new DomainException('O preço unitário não pode ser convertido com segurança.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
