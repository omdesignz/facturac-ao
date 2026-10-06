<?php

namespace App\Console\Commands;

use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Models\FiscalDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Freezes how every issued document without a print record prints.
 *
 * For documents issued before print records existed. Run once on deploy:
 * until then their issuer block is read from the live profile, so the sooner
 * it is frozen the less a company edit can drift into them. Every document
 * issued from now on is frozen at the moment of issue.
 */
#[Signature('fiscal:record-prints {--dry-run : Count what would be recorded without writing}')]
#[Description('Freezes the print record of every issued fiscal document that has none.')]
class RecordFiscalDocumentPrints extends Command
{
    public function handle(FiscalDocumentPrints $prints): int
    {
        $pending = FiscalDocument::query()
            ->whereNotNull('document_no')
            ->whereDoesntHave('printRecord');

        $count = (clone $pending)->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$count} documento(s) sem registo de impressão.");

            return self::SUCCESS;
        }

        $recorded = 0;
        $failed = 0;

        $pending->chunkById(200, function ($documents) use ($prints, &$recorded, &$failed): void {
            foreach ($documents as $document) {
                try {
                    $prints->record($document);
                    $recorded++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $this->components->error("{$document->document_no}: {$exception->getMessage()}");
                }
            }
        });

        $this->components->info("{$recorded} registo(s) criado(s), {$failed} falha(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
