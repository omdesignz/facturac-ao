<?php

namespace App\Billing\Contracts;

use App\Billing\Data\CreateEmisReferenceData;
use App\Billing\Data\EmisPaymentReferenceData;
use App\Billing\Data\EmisPaymentStatusData;

interface EmisPaymentGateway
{
    public function createReference(CreateEmisReferenceData $request): EmisPaymentReferenceData;

    public function status(string $providerReferenceId): EmisPaymentStatusData;
}
