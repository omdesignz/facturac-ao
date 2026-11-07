<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;

/**
 * Whether a document can be corrected with a credit note made from its own
 * lines, on the phone or on the web.
 *
 * Only invoices and invoice-receipts the AGT has validated, in the company's
 * own currency and without withholdings, with something left to credit. The
 * rest still has a way out, a note written by hand on the web, but not one
 * that copies the lines for the person.
 */
final readonly class CreditNoteEligibility
{
    public const array CORRECTABLE = [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt];

    public function __construct(private CreditNoteAllowance $allowance) {}

    public function obstacle(FiscalDocument $source): ?CreditNoteObstacle
    {
        return match (true) {
            ! in_array($source->document_type, self::CORRECTABLE, true) => CreditNoteObstacle::NotCorrectable,
            $source->status === FiscalDocumentStatus::Invalid => CreditNoteObstacle::Returned,
            ! CurrentAgtState::acceptanceEvidenceSatisfied($source) => CreditNoteObstacle::NotValidated,
            $source->currency_code !== $source->legalEntity->currency_code
                || $source->withholdings()->exists() => CreditNoteObstacle::ForeignCurrencyOrWithholdings,
            array_sum($this->allowance->remainingByLine($source)) === 0 => CreditNoteObstacle::FullyCredited,
            default => null,
        };
    }
}
