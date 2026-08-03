<?php

namespace App\Console\Commands;

use App\Actions\ExpireEmisPaymentReferences as ExpireReferences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:expire-emis-references')]
#[Description('Expire unpaid EMIS subscription references whose validity has ended')]
class ExpireEmisPaymentReferences extends Command
{
    public function handle(ExpireReferences $expireReferences): int
    {
        $count = $expireReferences->execute();
        $this->components->info("{$count} referência(s) EMIS expirada(s).");

        return self::SUCCESS;
    }
}
