<?php

namespace App\Jobs;

use App\Analytics\TransactionWorkbook;
use App\Models\LegalEntity;
use App\Models\TransactionExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

class GenerateTransactionExport implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $exportId)
    {
        $connection = (string) config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        $this->onConnection(in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true) ? $connection : 'database');
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'transaction-export:'.$this->exportId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(10)->expireAfter(75)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(TransactionWorkbook $workbook): void
    {
        $export = TransactionExport::find($this->exportId);
        if ($export === null || $export->expires_at->isPast() || in_array($export->status, ['ready', 'failed'], true)) {
            return;
        }
        if (! $export->requesterHasAccess()) {
            $this->failed(null);

            return;
        }
        $company = LegalEntity::query()->where('workspace_id', $export->workspace_id)->findOrFail($export->legal_entity_id);
        $export->update(['status' => 'processing', 'processed_documents' => 0]);
        $disk = Storage::disk('local');
        $temporary = $export->storagePath().'.part';
        $book = null;
        try {
            if (! $disk->makeDirectory('transaction-exports')) {
                throw new RuntimeException('Unable to create the private export directory.');
            }
            $book = $workbook->build($company, $export->filters, $export->document_ids, function (int $processed) use ($export): void {
                if ($processed % 50 === 0 || $processed === count($export->document_ids)) {
                    $export->update(['processed_documents' => $processed]);
                }
            });
            (new Xlsx($book))->save($disk->path($temporary));
            if (! $export->requesterHasAccess() || now()->greaterThanOrEqualTo($export->expires_at)) {
                $this->failed(null);

                return;
            }
            if (! $disk->move($temporary, $export->storagePath())) {
                throw new RuntimeException('Unable to publish the private export.');
            }
            activity('report')->causedBy(User::findOrFail($export->user_id))->performedOn($company)
                ->withProperties(['export_id' => $export->public_id, 'documents' => count($export->document_ids), 'sha256' => hash_file('sha256', $disk->path($export->storagePath()))])
                ->log('transactions exported to Excel in background');
            $export->update(['status' => 'ready']);
        } finally {
            $book?->disconnectWorksheets();
            $disk->delete($temporary);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = TransactionExport::find($this->exportId);
        if ($export !== null && $export->status !== 'ready') {
            Storage::disk('local')->delete([$export->storagePath(), $export->storagePath().'.part']);
            $export->update(['status' => 'failed', 'failure_message' => 'Não foi possível concluir o relatório. Verifique o acesso à empresa e tente um período menor.']);
        }
    }
}
