<?php

namespace App\Fiscal\Agt\Support;

use InvalidArgumentException;

final readonly class CanonicalNumber
{
    private function __construct(private string $value)
    {
        if (preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', $value) !== 1) {
            throw new InvalidArgumentException('The canonical number is not a valid JSON number.');
        }
    }

    public static function fromMinorUnits(int $minorUnits): self
    {
        return self::fromScaledInteger($minorUnits, 2);
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        return self::fromScaledInteger($basisPoints, 2);
    }

    public static function fromScaledInteger(int $value, int $scale): self
    {
        if ($scale < 0 || $scale > 9) {
            throw new InvalidArgumentException('The canonical number scale must be between zero and nine.');
        }

        if ($scale === 0) {
            return new self((string) $value);
        }

        $negative = $value < 0;
        $absoluteValue = abs($value);
        $factor = 10 ** $scale;
        $whole = intdiv($absoluteValue, $factor);
        $fraction = str_pad((string) ($absoluteValue % $factor), $scale, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');
        $number = ($negative ? '-' : '').$whole;

        return new self($fraction === '' ? $number : $number.'.'.$fraction);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
