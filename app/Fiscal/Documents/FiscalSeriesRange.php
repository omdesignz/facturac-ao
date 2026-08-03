<?php

namespace App\Fiscal\Documents;

use InvalidArgumentException;

final class FiscalSeriesRange
{
    public function sequence(string $documentNumber): int
    {
        $documentNumber = trim($documentNumber);

        if (preg_match('/(?:\A|\/)([0-9]{1,18})\z/', $documentNumber, $matches) !== 1) {
            throw new InvalidArgumentException('The AGT series range contains an invalid document number.');
        }

        $sequence = filter_var($matches[1], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($sequence)) {
            throw new InvalidArgumentException('The AGT series range is outside the supported integer range.');
        }

        return $sequence;
    }

    public function optionalSequence(?string $documentNumber): ?int
    {
        return filled($documentNumber)
            ? $this->sequence((string) $documentNumber)
            : null;
    }
}
