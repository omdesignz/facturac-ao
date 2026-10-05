<?php

namespace App\Providers;

use App\Billing\Contracts\EmisPaymentGateway;
use App\Billing\Contracts\HostedPaymentGateway;
use App\Billing\Contracts\WiPayTokenStore;
use App\Billing\Gateways\SimulatedPay4AllGateway;
use App\Billing\Gateways\UnavailablePay4AllGateway;
use App\Billing\WiPay\DatabaseWiPayTokenStore;
use App\Billing\WiPay\WiPayClient;
use App\Billing\WiPay\WiPayConfiguration;
use App\Pay4AllEnvironment;
use App\PaymentEnvironment;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(WiPayTokenStore::class, DatabaseWiPayTokenStore::class);
        $this->app->bind(HostedPaymentGateway::class, WiPayClient::class);
        $this->app->bind(WiPayConfiguration::class, fn (): WiPayConfiguration => new WiPayConfiguration(
            environment: PaymentEnvironment::tryFrom((string) config('billing.wipay.environment')),
            clientId: (string) config('billing.wipay.client_id'),
            clientSecret: (string) config('billing.wipay.client_secret'),
            productionEnabled: (bool) config('billing.wipay.production_enabled'),
            connectTimeoutSeconds: (int) config('billing.wipay.connect_timeout_seconds'),
            timeoutSeconds: (int) config('billing.wipay.timeout_seconds'),
        ));

        $this->app->bind(EmisPaymentGateway::class, function (): EmisPaymentGateway {
            $environment = Pay4AllEnvironment::tryFrom(
                (string) config('billing.pay4all.environment'),
            );

            if ($environment === Pay4AllEnvironment::Simulation) {
                return new SimulatedPay4AllGateway(
                    simulationEntity: (string) config('billing.pay4all.simulation_entity', '00000'),
                );
            }

            return new UnavailablePay4AllGateway;
        });
    }
}
