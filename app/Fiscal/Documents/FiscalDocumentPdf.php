<?php

namespace App\Fiscal\Documents;

use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Storage;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

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
    public function __construct(private FiscalDocumentPresenter $presenter) {}

    /** The finished PDF as a string, ready to attach or stream. */
    public function render(FiscalDocument $document): string
    {
        return LaravelMpdf::loadView(
            $this->template($document),
            $this->data($document),
            [],
            $this->configuration($document),
        )->output();
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

    /**
     * @return array<string, mixed>
     */
    private function data(FiscalDocument $document): array
    {
        $payload = $this->presenter->forPrint($document);

        return [
            'document' => $payload,
            'settlements' => $this->presenter->settlements($document),
            'logo' => $this->logo($document),
            // Passed in rather than done in the template: the sheet formats
            // money in a dozen places and they must not drift apart.
            'money' => fn (int $minor): string => number_format($minor / 100, 2, ',', ' '),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(FiscalDocument $document): array
    {
        return [
            'format' => (string) config('fiscal.print.paper', 'A4'),
            'orientation' => 'P',
            /*
             * mPDF draws the repeating header inside the top margin, so the
             * margin has to be as tall as the header or the body prints over
             * it. These are measured against the block in the templates.
             */
            'margin_top' => 36,
            'margin_bottom' => 18,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_header' => 8,
            'margin_footer' => 9,
            'title' => (string) $document->document_no,
            'author' => $document->legalEntity->legal_name,
            'subject' => $document->document_type->label(),
            // Angolan documents carry accented Portuguese throughout; the
            // default core fonts do not cover it.
            'mode' => 'utf-8',
            'default_font' => 'dejavusans',
        ];
    }

    /**
     * The logo as a data URI.
     *
     * Inlined because mPDF would otherwise have to fetch it, and a document
     * that renders differently depending on whether the disk is reachable is
     * not a document you can rely on.
     */
    private function logo(FiscalDocument $document): ?string
    {
        $path = $document->legalEntity->logo_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $contents);
    }
}
