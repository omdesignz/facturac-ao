<?php

use App\Exceptions\TenantAiStorageUnavailable;
use App\Fiscal\TenantAiVerificationReceipt;

function receiptVectors(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../docs/phase-7b-3c-1-migration-review-evidence.json'), true, flags: JSON_THROW_ON_ERROR)['canonicalization']['vectors'];
}

test('both frozen R3 receipt vectors reproduce exact signed bytes and public test MAC', function () {
    foreach (receiptVectors() as $vector) {
        $fields = array_combine(TenantAiVerificationReceipt::FIELDS, $vector['array']);
        $bytes = TenantAiVerificationReceipt::canonical($fields);
        expect($bytes)->toBe(hex2bin($vector['domain_prefix_hex']).$vector['canonical_json'])
            ->and(strlen($bytes))->toBe($vector['bytes_length'])
            ->and(hash('sha256', $bytes))->toBe($vector['sha256'])
            ->and(TenantAiVerificationReceipt::authentic($fields, $vector['hmac_sha256_with_public_zero_test_key'], str_repeat("\0", 32)))->toBeTrue();
    }
});

test('every original signed position rejects its frozen authentication after mutation', function () {
    $mutations = 0;
    foreach (receiptVectors() as $vector) {
        foreach (TenantAiVerificationReceipt::FIELDS as $position => $field) {
            $fields = array_combine(TenantAiVerificationReceipt::FIELDS, $vector['array']);
            $fields[$field] = is_array($fields[$field]) ? [['budget_control', 'changed', '1']] : ($fields[$field] === null ? '1' : $fields[$field].'x');
            expect(TenantAiVerificationReceipt::authentic($fields, $vector['hmac_sha256_with_public_zero_test_key'], str_repeat("\0", 32)))->toBeFalse();
            $mutations++;
        }
    }
    expect($mutations)->toBe(94);
});

test('canonical receipt rejects numeric coercion missing fields wrong case overflow and excessive references', function (string $case) {
    $fields = array_combine(TenantAiVerificationReceipt::FIELDS, receiptVectors()[0]['array']);
    match ($case) {
        'float' => $fields['credential_version_number'] = 2.0,
        'integer' => $fields['credential_version_number'] = 2,
        'overflow' => $fields['credential_version_number'] = '9223372036854775808',
        'leading zero' => $fields['credential_version_number'] = '02',
        'uppercase' => $fields['workspace_public_id'] = strtoupper($fields['workspace_public_id']),
        'timestamp' => $fields['observed_at'] = '2026-02-30T12:00:00.000000Z',
        'extra' => $fields['extra'] = 'unapproved',
        'many references' => $fields['authority_references'] = array_map(fn ($n) => ['budget_control', (string) $n, '1'], range(1, 17)),
        'duplicate references' => $fields['authority_references'][] = $fields['authority_references'][0],
    };
    expect(fn () => TenantAiVerificationReceipt::canonical($fields))->toThrow(TenantAiStorageUnavailable::class);
})->with(['float', 'integer', 'overflow', 'leading zero', 'uppercase', 'timestamp', 'extra', 'many references', 'duplicate references']);

test('authority reference kinds and identity shapes remain closed', function (array $reference) {
    $fields = array_combine(TenantAiVerificationReceipt::FIELDS, receiptVectors()[0]['array']);
    $fields['authority_references'] = [$reference];
    expect(fn () => TenantAiVerificationReceipt::canonical($fields))->toThrow(TenantAiStorageUnavailable::class);
})->with([
    [['admin', '00000001-0000-4000-8000-000000000001', '1']],
    [['owner_approval', '01', '1']],
    [['gateway_control', '1', '1']],
    [['budget_control', '00000001-0000-4000-8000-00000000000A', '1']],
]);
