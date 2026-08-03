<?php

namespace App\Actions;

use App\LegalEntityStatus;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class SaveCompanyProfile
{
    /**
     * @param  array{
     *     legal_name: string,
     *     trade_name: string|null,
     *     tax_identification_number: string,
     *     tax_regime: string,
     *     main_cae_code: string,
     *     establishment_code: string,
     *     establishment_name: string,
     *     address_line: string,
     *     municipality: string,
     *     province_code: string
     * }  $data
     */
    public function execute(Workspace $workspace, array $data): LegalEntity
    {
        return DB::transaction(function () use ($workspace, $data): LegalEntity {
            $legalEntity = LegalEntity::query()
                ->where('workspace_id', $workspace->id)
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if ($legalEntity !== null && ! in_array($legalEntity->status, [
                LegalEntityStatus::Draft,
                LegalEntityStatus::Configured,
            ], true)) {
                throw new LogicException('The legal entity profile is locked after homologation begins.');
            }

            $legalEntity ??= new LegalEntity([
                'workspace_id' => $workspace->id,
                'country_code' => 'AO',
                'currency_code' => 'AOA',
                'timezone' => 'Africa/Luanda',
            ]);

            $onboardingCompletedAt = $legalEntity->onboarding_completed_at ?? now();

            $legalEntity->fill([
                'legal_name' => trim($data['legal_name']),
                'trade_name' => filled($data['trade_name']) ? trim($data['trade_name']) : null,
                'tax_identification_number' => Str::upper($data['tax_identification_number']),
                'tax_regime' => $data['tax_regime'],
                'main_cae_code' => Str::upper($data['main_cae_code']),
                'status' => LegalEntityStatus::Configured,
                'onboarding_completed_at' => $onboardingCompletedAt,
            ])->save();

            $establishment = Establishment::query()
                ->where('workspace_id', $workspace->id)
                ->where('legal_entity_id', $legalEntity->id)
                ->where('is_head_office', true)
                ->lockForUpdate()
                ->first();

            $establishment ??= new Establishment([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'timezone' => 'Africa/Luanda',
                'is_head_office' => true,
                'is_active' => true,
            ]);

            $establishment->fill([
                'code' => Str::upper($data['establishment_code']),
                'name' => trim($data['establishment_name']),
                'address_line' => trim($data['address_line']),
                'municipality' => trim($data['municipality']),
                'province_code' => Str::upper($data['province_code']),
            ])->save();

            return $legalEntity->load('establishments');
        });
    }
}
