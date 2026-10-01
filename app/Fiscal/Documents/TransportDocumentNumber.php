<?php

namespace App\Fiscal\Documents;

use App\TransportDocumentType;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransportDocumentNumber
{
    public function compose(
        TransportDocumentType $documentType,
        string $seriesCode,
        int $sequence,
    ): string {
        $seriesCode = Str::upper(trim($seriesCode));

        if (preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,31}\z/', $seriesCode) !== 1) {
            throw new InvalidArgumentException('The transport series code is invalid.');
        }

        if ($sequence < 1) {
            throw new InvalidArgumentException('The transport sequence must start at one.');
        }

        $documentNumber = "{$documentType->value} {$seriesCode}/{$sequence}";

        if (mb_strlen($documentNumber) > 60) {
            throw new InvalidArgumentException('The transport document number is too long.');
        }

        return $documentNumber;
    }
}
