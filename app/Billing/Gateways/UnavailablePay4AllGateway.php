<?php

namespace App\Billing\Gateways;

use App\Billing\Contracts\EmisPaymentGateway;
use App\Billing\Data\CreateEmisReferenceData;
use App\Billing\Data\EmisPaymentReferenceData;
use App\Billing\Data\EmisPaymentStatusData;
use App\Billing\Exceptions\BillingGatewayUnavailable;

final class UnavailablePay4AllGateway implements EmisPaymentGateway
{
    public function createReference(CreateEmisReferenceData $request): EmisPaymentReferenceData
    {
        throw BillingGatewayUnavailable::pay4AllContractRequired();
    }

    public function status(string $providerReferenceId): EmisPaymentStatusData
    {
        throw BillingGatewayUnavailable::pay4AllContractRequired();
    }
}
