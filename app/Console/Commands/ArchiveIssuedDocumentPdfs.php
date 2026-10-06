<?php

namespace App\Console\Commands;

use App\Fiscal\Documents\FiscalDocumentArchive;
use App\Models\FiscalDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps the PDF of every issued document that does not have one yet.
 *
 * For documents issued before PDFs were archived, and as a safety net for any
 * archive job that gave up. Rendered with today's template, which is the best
 * record still available for those; every document issued from now on is
 * archived at the moment of issue.
 */
#[Signature('fiscal:archive-pdfs {--dry-run : Count what would be archived without rendering}')]
#[Description('Renders and archives the PDF of every issued fiscal document that has none.')]
class ArchiveIssuedDocumentPdfs extends Command
{
    public function handle(FiscalDocumentArchive $archive): int
    {
        $pending = FiscalDocument::query()
            ->whereNotNull('document_no')
            ->whereDoesntHave('archivedPdf');

        $count = (clone $pending)->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$count} documento(s) sem PDF arquivado.");

            return self::SUCCESS;
        }

        $archived = 0;
        $failed = 0;

        $pending->orderBy('id')->chunkById(100, function ($documents) use ($archive, &$archived, &$failed): void {
            foreach ($documents as $document) {
                try {
                    $archive->store($document);
                    $archived++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $this->components->error("{$document->document_no}: {$exception->getMessage()}");
                }
            }
        });

        $this->components->info("{$archived} PDF(s) arquivado(s), {$failed} falha(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
