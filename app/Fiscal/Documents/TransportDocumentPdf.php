<?php

namespace App\Fiscal\Documents;

use App\Models\TransportDocument;
use Illuminate\Support\Facades\Storage;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

final readonly class TransportDocumentPdf
{
    public function __construct(private TransportDocumentPresenter $presenter) {}

    public function render(TransportDocument $document): string
    {
        return LaravelMpdf::loadView(
            'transport-documents.pdf.guide',
            [
                'document' => $this->presenter->forPrint($document),
                'logo' => $this->logo($document),
                'money' => fn (int $minor): string => number_format($minor / 100, 2, ',', ' '),
            ],
            [],
            [
                'format' => (string) config('fiscal.print.paper', 'A4'),
                'orientation' => 'P',
                'margin_top' => 35,
                'margin_bottom' => 18,
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_header' => 8,
                'margin_footer' => 9,
                'title' => (string) $document->document_no,
                'author' => $document->legalEntity->legal_name,
                'subject' => $document->document_type->label(),
                'mode' => 'utf-8',
                'default_font' => 'dejavusans',
            ],
        )->output();
    }

    public function filename(TransportDocument $document): string
    {
        return str_replace(['/', ' '], '-', (string) $document->document_no).'.pdf';
    }

    private function logo(TransportDocument $document): ?string
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
