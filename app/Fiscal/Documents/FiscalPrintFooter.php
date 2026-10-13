<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentType;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class FiscalPrintFooter
{
    /** AGT Decreto Executivo 74/19, annex 6(b): one-based positions of the SAF-T signature. */
    public function signatureExcerpt(string $signature): string
    {
        if (strlen($signature) < 31) {
            throw new InvalidArgumentException('The fiscal signature must contain at least 31 characters.');
        }

        return $signature[0].$signature[10].$signature[20].$signature[30];
    }

    public function text(
        FiscalDocumentType $type,
        ?string $validationNumber,
        ?string $signature = null,
        ?CarbonInterface $availableAt = null,
    ): string {
        $parts = [];
        if (filled($validationNumber)) {
            $verb = $type->settlesOtherDocuments() ? 'Emitido' : 'Processado';
            $prefix = ! $type->settlesOtherDocuments() && $signature !== null
                ? $this->signatureExcerpt($signature).'-'
                : '';
            $parts[] = $prefix.$verb.' por programa validado n.º '.$validationNumber;
        }

        if (! in_array($type, [FiscalDocumentType::Receipt, FiscalDocumentType::CreditNote], true) && $availableAt !== null) {
            $format = $type === FiscalDocumentType::InvoiceReceipt ? 'Y-m-d' : 'Y-m-d - H:i';
            $parts[] = 'Os bens e/ou serviços foram colocados à disposição na data '.$availableAt->format($format);
        }

        return ($parts === [] ? '' : implode(' | ', $parts).' / ').'facturac.ao';
    }
}
