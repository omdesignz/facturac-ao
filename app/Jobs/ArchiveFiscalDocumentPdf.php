<?php

namespace App\Jobs;

use App\Fiscal\Documents\FiscalDocumentArchive;
use App\Models\FiscalDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders and keeps the PDF of a document the moment it is issued, so the
 * record is made while nothing about the sheet can have changed yet. Anything
 * that asks for the PDF first simply stores it itself; this only makes sure
 * no document goes long without its copy.
 */
class ArchiveFiscalDocumentPdf implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $fiscalDocumentId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return "archive-pdf:{$this->fiscalDocumentId}";
    }

    public function handle(FiscalDocumentArchive $archive): void
    {
        $document = FiscalDocument::query()->find($this->fiscalDocumentId);

        if ($document === null || $document->document_no === null) {
            return;
        }

        $archive->store($document);
    }
}
