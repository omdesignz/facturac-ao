<?php

namespace App\Console\Commands;

use App\Fiscal\AssistantExecutionGuard;
use App\Fiscal\AssistantProviderLedger;
use Illuminate\Console\Command;

final class RecoverAssistantProviderAttempts extends Command
{
    protected $signature = 'assistant:recover-provider-attempts';

    protected $description = 'Classify at most 100 stale provider attempts without resending or releasing reservations';

    public function handle(AssistantProviderLedger $ledger): int
    {
        try {
            $count = AssistantExecutionGuard::database(fn (): int => $ledger->recover());
            $this->info('Stale provider attempts inspected: '.$count);

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Provider accounting recovery unavailable. No inference was attempted.');

            return self::FAILURE;
        }
    }
}
