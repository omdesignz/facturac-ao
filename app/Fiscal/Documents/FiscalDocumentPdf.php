<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPrint;

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

    /**
     * The finished PDF as a string, ready to attach or stream, drawn from the
     * print record: its layout version, its frozen issuer and its logo.
     * Callers go through FiscalDocumentPrints, which checks the record first.
     */
    public function render(FiscalDocument $document, FiscalDocumentPrint $print): string
    {
        return $this->sheet->render(
            $this->template($document, $print->layout_version),
            [
                'document' => $this->presenter->forPrint($document, $print),
                'settlements' => $this->presenter->settlements($document),
                'logo' => $this->sheet->logoFromPath($print->logo_path),
            ],
            [
                'title' => (string) $document->document_no,
                'author' => $print->issuer['legal_name'],
                'subject' => $document->document_type->label(),
                'margin_top' => (int) config("fiscal.print.layouts.{$print->layout_version}.margin_top", 34),
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
     *
     * @return view-string
     */
    private function template(FiscalDocument $document, string $layoutVersion): string
    {
        $sheet = in_array($document->document_type, [
            FiscalDocumentType::Receipt,
        ], true)
            ? 'receipt'
            : 'invoice';

        /** @var view-string $view */
        $view = "documents.pdf.{$layoutVersion}.{$sheet}";

        return $view;
    }
}
