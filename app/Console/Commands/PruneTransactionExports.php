<?php

namespace App\Console\Commands;

use App\Models\TransactionExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneTransactionExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Remove expired private Excel reports and abandoned files';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        foreach (TransactionExport::query()->where('expires_at', '<=', now())->lazyById(100) as $export) {
            if ($disk->delete([$export->storagePath(), $export->storagePath().'.part'])) {
                $export->delete();
            }
        }
        foreach ($disk->files('transaction-exports') as $path) {
            if ($disk->lastModified($path) < now()->subDay()->timestamp) {
                $disk->delete($path);
            }
        }

        return self::SUCCESS;
    }
}
