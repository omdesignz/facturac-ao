<?php

namespace App\Fiscal;

class ServiceTaxProfile
{
    /** @return array{type: string, code: ?string, percentage: string, exemption_code: ?string} */
    public function resolve(string $token): array
    {
        $treatment = SupportedTaxTreatment::tryFrom($token);
        abort_unless($treatment !== null, 503);

        return $treatment->profile();
    }

    /** @return array{type: string, code: ?string, percentage: string, exemption_code: ?string} */
    public function verified(string $token, bool $external): array
    {
        $profile = $this->resolve($token);
        $treatment = SupportedTaxTreatment::fromComponents($profile['type'], $profile['code'], $profile['percentage'], $profile['exemption_code']);
        abort_unless($treatment !== null && $treatment->value === $token, 503);
        if ($external) {
            $expected = match ($token) {
                'IVA_NOR_14' => ['IVA', 'NOR', '14', null],
                'IVA_ISE_M00' => ['IVA', 'ISE', '0', 'M00'],
                'NS_M02' => ['NS', null, '0', 'M02'],
                'IVA_ISE_M04' => ['IVA', 'ISE', '0', 'M04'],
            };
            abort_unless(array_values($profile) === $expected, 503);
        }

        return $profile;
    }
}
