<?php

namespace App\Billing\Contracts;

use App\Billing\Data\CreateHostedPaymentData;
use App\Billing\Data\HostedPaymentData;

interface HostedPaymentGateway
{
    public function signatureToken(): string;

    public function createPayment(CreateHostedPaymentData $request): HostedPaymentData;
}
