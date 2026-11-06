<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\MobileInvoice;

/**
 * What the phone is told about a document it just made.
 */
final readonly class MobileDocumentReceipt
{
    public function __construct(
        private FiscalDocumentRegister $register,
        private FiscalDocumentPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(MobileInvoice $made, LegalEntity $legalEntity): array
    {
        return $this->forDocument($made->fiscalDocument, $made->replayed, $legalEntity);
    }

    /**
     * @return array<string, mixed>
     */
    public function forDocument(FiscalDocument $document, bool $replayed, LegalEntity $legalEntity): array
    {
        $selected = (array) $this->register->selected($legalEntity, $document->public_id, false);

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'customer_name' => $document->customer_name,
            'gross_total_minor' => $document->gross_total_minor,
            'currency_code' => $document->currency_code,
            'status_label' => $selected['workflow_label'],
            'is_issued' => $document->document_no !== null,
            'share_url' => $document->document_no === null ? null : $this->presenter->signedUrl($document),
            'replayed' => $replayed,
        ];
    }
}
