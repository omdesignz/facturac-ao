<?php

namespace App\Console\Commands;

use App\Billing\WiPay\WiPayClient;
use App\Billing\WiPay\WiPayConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('wipay:check')]
#[Description('Verify payment and signature credentials without creating a payment or exposing tokens')]
class CheckWiPayConnection extends Command
{
    public function handle(WiPayClient $client, WiPayConfiguration $configuration): int
    {
        try {
            $client->checkConnection();
        } catch (Throwable) {
            $this->components->error('WiPay não está disponível. Verifique as credenciais, as migrações e a ligação. Nenhum pagamento foi criado.');

            return self::FAILURE;
        }

        $this->components->info('WiPay: autenticação e assinatura verificadas em '.($configuration->environment->value ?? 'invalid').'. Nenhum pagamento foi criado.');

        return self::SUCCESS;
    }
}
