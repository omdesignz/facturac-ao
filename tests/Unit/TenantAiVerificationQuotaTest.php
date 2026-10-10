<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiVerificationAdmission;
use App\Fiscal\TenantAiVerificationQuota;

test('CR1 uses the exact domain separated signed int4 identity vector', function () {
    expect(TenantAiVerificationQuota::key('00000007-0000-4000-8000-000000000001'))->toBe(-1169568379);
    expect(TenantAiVerificationQuota::key('00000007-0000-4000-8000-000000000002'))
        ->not->toBe(TenantAiVerificationQuota::key('00000007-0000-4000-8000-000000000001'));
});

test('CR1 rejects noncanonical or substituted identity representations', function (string $identity) {
    expect(fn () => TenantAiVerificationQuota::key($identity))->toThrow(TenantAiStorageUnavailable::class);
})->with(['', '1', '0000000A-0000-4000-8000-000000000001', '01arz3ndektsv4rrffq69g5fav',
    "00000007-0000-4000-8000-000000000001\n", 'tenant:00000007-0000-4000-8000-000000000001']);

test('reservation arithmetic remains integer and fails closed at overflow', function () {
    expect(TenantAiVerificationAdmission::add(PHP_INT_MAX - 1, 1))->toBe(PHP_INT_MAX);
    foreach ([[PHP_INT_MAX, 1], [-1, 0], [0, -1]] as [$current, $amount]) {
        expect(fn () => TenantAiVerificationAdmission::add($current, $amount))->toThrow(TenantAiStorageUnavailable::class);
    }
});
