<?php

namespace App\Providers;

use App\Billing\Contracts\EmisPaymentGateway;
use App\Billing\Gateways\SimulatedPay4AllGateway;
use App\Billing\Gateways\UnavailablePay4AllGateway;
use App\Pay4AllEnvironment;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(EmisPaymentGateway::class, function (): EmisPaymentGateway {
            $environment = Pay4AllEnvironment::tryFrom(
                (string) config('billing.pay4all.environment'),
            ) ?? Pay4AllEnvironment::Simulation;

            if ($environment === Pay4AllEnvironment::Simulation) {
                return new SimulatedPay4AllGateway(
                    simulationEntity: (string) config('billing.pay4all.simulation_entity', '00000'),
                );
            }

            return new UnavailablePay4AllGateway;
        });
    }
}
