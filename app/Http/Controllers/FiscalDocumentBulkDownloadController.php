<?php

namespace App\Http\Controllers;

use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Fiscal\Documents\PrintRecordMismatch;
use App\Http\Requests\DownloadFiscalDocumentsRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Several issued documents as one ZIP of the same PDFs each row serves alone.
 *
 * All or nothing: a file missing a document it was asked for would pass for
 * complete, so one refused print refuses the download and says which.
 */
class FiscalDocumentBulkDownloadController extends Controller
{
    public function __construct(
        private FiscalDocumentPdf $pdf,
        private FiscalDocumentPrints $prints,
    ) {}

    public function __invoke(DownloadFiscalDocumentsRequest $request): BinaryFileResponse|RedirectResponse
    {
        $legalEntity = $request->currentLegalEntity();
        abort_unless($legalEntity instanceof LegalEntity, 404);

        /** @var list<string> $publicIds */
        $publicIds = $request->validated('documentos');
        $documents = FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('public_id', $publicIds)
            ->whereNotNull('document_no')
            ->orderBy('id')
            ->get();

        abort_unless($documents->count() === count($publicIds), 404);

        $path = (string) tempnam(sys_get_temp_dir(), 'documentos');
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Não foi possível preparar o ficheiro de documentos.');
        }

        foreach ($documents as $document) {
            try {
                $archive->addFromString($this->pdf->filename($document), $this->prints->pdf($document));
            } catch (PrintRecordMismatch) {
                $archive->unchangeAll();
                $archive->close();
                @unlink($path);

                return back()->with('error', "Não foi possível preparar o PDF de {$document->document_no}. Nada foi descarregado.");
            }
        }

        $archive->close();

        return response()
            ->download($path, 'documentos-'.now()->format('Ymd-His').'.zip', [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }
}
