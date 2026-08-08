<?php

namespace App\Console\Commands;

use App\Actions\GenerateRecurringInvoices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:generate-recurring-invoices')]
#[Description('Raise the documents that recurring profiles are due to produce')]
class GenerateDueRecurringInvoices extends Command
{
    public function handle(GenerateRecurringInvoices $generate): int
    {
        $result = $generate->execute();

        $this->components->info(sprintf(
            '%d documento(s) gerado(s) a partir de %d perfil(is).',
            $result['generated'],
            $result['profiles'],
        ));

        if ($result['failed'] > 0) {
            $this->components->warn(
                "{$result['failed']} perfil(is) falharam e ficaram para a próxima execução.",
            );
        }

        return self::SUCCESS;
    }
}
