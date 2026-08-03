<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentType;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FiscalDocumentNumber
{
    public function compose(FiscalDocumentType $documentType, string $seriesCode, int $sequence): string
    {
        $seriesCode = Str::upper(trim($seriesCode));

        if (preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,31}\z/', $seriesCode) !== 1) {
            throw new InvalidArgumentException('The AGT series code is invalid.');
        }

        if ($sequence < 1) {
            throw new InvalidArgumentException('The fiscal sequence must start at one.');
        }

        $documentNumber = "{$documentType->value} {$seriesCode}/{$sequence}";

        if (mb_strlen($documentNumber) < 8 || mb_strlen($documentNumber) > 60) {
            throw new InvalidArgumentException('The fiscal document number must contain between 8 and 60 characters.');
        }

        return $documentNumber;
    }
}
