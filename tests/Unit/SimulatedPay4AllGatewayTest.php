<?php

use App\Billing\Data\CreateEmisReferenceData;
use App\Billing\Gateways\SimulatedPay4AllGateway;
use App\EmisPaymentReferenceStatus;
use Carbon\CarbonImmutable;

test('the simulation gateway is deterministic and visibly non-payable', function () {
    $gateway = new SimulatedPay4AllGateway;
    $expiresAt = CarbonImmutable::parse('2026-08-03T12:00:00+01:00');
    $request = new CreateEmisReferenceData(
        idempotencyKey: '01K1ABCDEF0123456789ABCDE',
        merchantReference: '01K1CHARGE0123456789ABCDE',
        amountMinor: 25_000_00,
        currencyCode: 'AOA',
        description: 'Plano Crescer',
        requestedExpiresAt: $expiresAt,
    );

    $first = $gateway->createReference($request);
    $second = $gateway->createReference($request);

    expect($second)->toEqual($first)
        ->and($first->entity)->toBe('00000')
        ->and($first->reference)->toMatch('/\A\d{9}\z/')
        ->and($first->amountMinor)->toBe(25_000_00)
        ->and($first->expiresAt)->toEqual($expiresAt)
        ->and($first->payloadSha256)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($gateway->status($first->providerReferenceId)->status)
        ->toBe(EmisPaymentReferenceStatus::Pending);
});
