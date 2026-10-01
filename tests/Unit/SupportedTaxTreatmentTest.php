<?php

use App\Fiscal\SupportedTaxTreatment;

test('the supported treatments expose the exact AGT components', function () {
    expect(SupportedTaxTreatment::options())->toBe([
        [
            'value' => 'IVA_NOR_14',
            'label' => 'IVA · taxa normal (14%)',
            'type' => 'IVA',
            'code' => 'NOR',
            'percentage' => '14',
            'exemption_code' => null,
        ],
        [
            'value' => 'IVA_ISE_M00',
            'label' => 'Isento · regime simplificado (M00)',
            'type' => 'IVA',
            'code' => 'ISE',
            'percentage' => '0',
            'exemption_code' => 'M00',
        ],
        [
            'value' => 'NS_M02',
            'label' => 'Não sujeito (M02)',
            'type' => 'NS',
            'code' => null,
            'percentage' => '0',
            'exemption_code' => 'M02',
        ],
        [
            'value' => 'IVA_ISE_M04',
            'label' => 'Isento · exclusão (M04)',
            'type' => 'IVA',
            'code' => 'ISE',
            'percentage' => '0',
            'exemption_code' => 'M04',
        ],
    ]);
});

test('a legacy normal VAT row with a missing code is repaired unambiguously', function () {
    expect(SupportedTaxTreatment::fromLegacyComponents('IVA', null, '14.00', null))
        ->toBe(SupportedTaxTreatment::VatNormal14)
        ->and(SupportedTaxTreatment::fromComponents('IVA', null, '14.00', null))
        ->toBeNull();
});

test('an explicit contradictory tax code is never silently rewritten', function () {
    expect(SupportedTaxTreatment::fromLegacyComponents('IVA', 'ISE', '14', 'M02'))
        ->toBeNull();
});
