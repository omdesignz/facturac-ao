<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentType;
use App\Models\FiscalDocument;

/**
 * The document as a PDF, laid out the way the AGT publishes it.
 *
 * mPDF rather than the browser's own print, because a fiscal document is not
 * a web page that happens to be printed. It needs the header and the column
 * titles repeated on every sheet, "Pág. 2/3" in the footer, and a line item
 * that must never be split down the middle by a page break. Those are things
 * a print stylesheet asks for politely and a PDF engine actually does.
 */
class FiscalDocumentPdf
{
    public function __construct(
        private FiscalDocumentPresenter $presenter,
        private PdfSheet $sheet,
    ) {}

    /** The finished PDF as a string, ready to attach or stream. */
    public function render(FiscalDocument $document): string
    {
        return $this->sheet->render(
            $this->template($document),
            [
                'document' => $this->presenter->forPrint($document),
                'settlements' => $this->presenter->settlements($document),
                'logo' => $this->sheet->logo($document->legalEntity),
            ],
            [
                'title' => (string) $document->document_no,
                'author' => $document->legalEntity->legal_name,
                'subject' => $document->document_type->label(),
            ],
        );
    }

    public function filename(FiscalDocument $document): string
    {
        $number = str_replace(['/', ' '], ['-', '-'], (string) $document->document_no);

        return "{$number}.pdf";
    }

    /**
     * Receipts carry settled documents where an invoice carries line items, so
     * they are a different sheet rather than the same one with a branch in it.
     */
    private function template(FiscalDocument $document): string
    {
        return in_array($document->document_type, [
            FiscalDocumentType::Receipt,
        ], true)
            ? 'documents.pdf.receipt'
            : 'documents.pdf.invoice';
    }
}
