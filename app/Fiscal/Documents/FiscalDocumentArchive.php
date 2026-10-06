<?php

namespace App\Fiscal\Documents;

use App\Models\ArchivedPdf;
use App\Models\FiscalDocument;
use Composer\InstalledVersions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Every issued document's PDF, rendered once and served as stored from then on.
 *
 * Re-rendering on request would let a later template, logo, font or library
 * change quietly alter documents customers already hold, and the auditor
 * would have no way to tell. So the first render is the record: kept on a
 * private disk with its SHA-256, and every later request gets those exact
 * bytes after the hash is checked. A copy that no longer matches is refused,
 * never silently replaced.
 */
class FiscalDocumentArchive
{
    /** Long enough for a several-hundred-line render; short enough not to wedge a request. */
    private const int LOCK_SECONDS = 60;

    private const int LOCK_WAIT_SECONDS = 20;

    public function __construct(private FiscalDocumentPdf $renderer) {}

    /** The document's PDF as issued, rendering and storing it on first use. */
    public function pdf(FiscalDocument $document): string
    {
        $archived = $document->archivedPdf()->first();

        return $archived instanceof ArchivedPdf
            ? $this->read($document, $archived)
            : $this->read($document, $this->store($document));
    }

    /**
     * Render and keep the PDF if it has not been kept yet. Safe to call any
     * number of times and from several workers at once: only one copy ever
     * becomes the record.
     */
    public function store(FiscalDocument $document): ArchivedPdf
    {
        if ($document->document_no === null) {
            throw new LogicException('A draft has no number and nothing to archive.');
        }

        return Cache::lock("fiscal-document-pdf:{$document->id}", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($document): ArchivedPdf {
                $existing = $document->archivedPdf()->first();

                if ($existing instanceof ArchivedPdf) {
                    return $existing;
                }

                $bytes = $this->renderer->render($document);
                $sha256 = hash('sha256', $bytes);
                $disk = (string) config('fiscal.print.archive_disk', 'local');
                $path = $this->path($document, $sha256);

                if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
                    throw new ArchivedPdfCompromised("The PDF for {$document->document_no} could not be written to the archive.");
                }

                try {
                    return ArchivedPdf::query()->create([
                        'workspace_id' => $document->workspace_id,
                        'legal_entity_id' => $document->legal_entity_id,
                        'fiscal_document_id' => $document->id,
                        'disk' => $disk,
                        'path' => $path,
                        'sha256' => $sha256,
                        'byte_size' => strlen($bytes),
                        'renderer' => $this->rendererVersion(),
                        'rendered_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another server won the race past the lock. Its copy is
                    // the record; this one was never referenced, so it goes.
                    Storage::disk($disk)->delete($path);

                    return $document->archivedPdf()->firstOrFail();
                }
            });
    }

    private function read(FiscalDocument $document, ArchivedPdf $archived): string
    {
        $bytes = Storage::disk($archived->disk)->get($archived->path);

        if ($bytes === null || ! hash_equals($archived->sha256, hash('sha256', $bytes))) {
            Log::critical('Archived fiscal document PDF is missing or altered.', [
                'document_public_id' => $document->public_id,
                'document_no' => $document->document_no,
                'disk' => $archived->disk,
                'path' => $archived->path,
            ]);

            throw new ArchivedPdfCompromised("The archived PDF for {$document->document_no} does not match its record.");
        }

        return $bytes;
    }

    /**
     * Grouped by company and year so a retention sweep or an auditor's export
     * walks one folder. The hash in the name means two racing renders can
     * never overwrite each other's file.
     */
    private function path(FiscalDocument $document, string $sha256): string
    {
        return sprintf(
            '%s/%d/%d/%s/%s-%s.pdf',
            trim((string) config('fiscal.print.archive_directory', 'fiscal-documents'), '/'),
            $document->workspace_id,
            $document->legal_entity_id,
            $document->document_date->format('Y'),
            $document->public_id,
            substr($sha256, 0, 12),
        );
    }

    private function rendererVersion(): string
    {
        return 'mPDF '.(InstalledVersions::getPrettyVersion('mpdf/mpdf') ?? 'unknown');
    }
}
