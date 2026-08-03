<?php

namespace App\Billing\Gateways;

use App\Billing\Contracts\EmisPaymentGateway;
use App\Billing\Data\CreateEmisReferenceData;
use App\Billing\Data\EmisPaymentReferenceData;
use App\Billing\Data\EmisPaymentStatusData;
use App\EmisPaymentReferenceStatus;
use Carbon\CarbonImmutable;

final readonly class SimulatedPay4AllGateway implements EmisPaymentGateway
{
    public function __construct(
        private string $simulationEntity = '00000',
    ) {}

    public function createReference(CreateEmisReferenceData $request): EmisPaymentReferenceData
    {
        $digest = hash('sha256', $request->idempotencyKey);
        $referenceNumber = (string) (hexdec(substr($digest, 0, 8)) % 1_000_000_000);
        $reference = str_pad($referenceNumber, 9, '0', STR_PAD_LEFT);
        $providerReferenceId = 'sim_'.substr($digest, 0, 24);
        $payload = json_encode([
            'amount_minor' => $request->amountMinor,
            'currency_code' => $request->currencyCode,
            'entity' => $this->simulationEntity,
            'expires_at' => $request->requestedExpiresAt->toIso8601String(),
            'merchant_reference' => $request->merchantReference,
            'provider_reference_id' => $providerReferenceId,
            'reference' => $reference,
            'simulation' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new EmisPaymentReferenceData(
            providerReferenceId: $providerReferenceId,
            entity: $this->simulationEntity,
            reference: $reference,
            amountMinor: $request->amountMinor,
            currencyCode: $request->currencyCode,
            expiresAt: $request->requestedExpiresAt,
            payloadSha256: hash('sha256', $payload),
        );
    }

    public function status(string $providerReferenceId): EmisPaymentStatusData
    {
        $occurredAt = CarbonImmutable::now('Africa/Luanda');
        $payload = json_encode([
            'provider_reference_id' => $providerReferenceId,
            'simulation' => true,
            'status' => EmisPaymentReferenceStatus::Pending->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new EmisPaymentStatusData(
            status: EmisPaymentReferenceStatus::Pending,
            providerEventId: null,
            amountMinor: 0,
            currencyCode: 'AOA',
            occurredAt: $occurredAt,
            payloadSha256: hash('sha256', $payload),
        );
    }
}
