<?php

namespace App\Fiscal\Documents;

use App\Models\TransportDocument;
use App\TransportDocumentStatus;

final readonly class TransportDocumentPdf
{
    public function __construct(
        private TransportDocumentPresenter $presenter,
        private PdfSheet $sheet,
    ) {}

    public function render(TransportDocument $document): string
    {
        return $this->sheet->render(
            'transport-documents.pdf.guide',
            [
                'document' => $this->presenter->forPrint($document),
                'logo' => $this->sheet->logo($document->legalEntity),
            ],
            [
                'title' => (string) $document->document_no,
                'author' => $document->legalEntity->legal_name,
                'subject' => $document->document_type->label(),
                'margin_top' => 33,
                'watermark' => $document->status === TransportDocumentStatus::Cancelled ? 'ANULADO' : null,
            ],
        );
    }

    public function filename(TransportDocument $document): string
    {
        return str_replace(['/', ' '], '-', (string) $document->document_no).'.pdf';
    }
}
