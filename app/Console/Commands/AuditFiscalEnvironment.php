<?php

namespace App\Console\Commands;

use App\Fiscal\FiscalEnvironmentBackfill;
use Illuminate\Console\Command;
use RuntimeException;

class AuditFiscalEnvironment extends Command
{
    protected $signature = 'fiscal:environment-audit';

    protected $description = 'Read-only audit of deterministic fiscal environment backfill and unresolved document IDs';

    public function handle(FiscalEnvironmentBackfill $backfill): int
    {
        try {
            $this->line(json_encode($backfill->scan(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
