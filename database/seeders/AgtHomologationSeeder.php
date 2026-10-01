<?php

namespace Database\Seeders;

use App\LegalEntityStatus;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Province;
use App\TaxRegime;
use App\WorkspaceRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgtHomologationSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $alias = Str::upper(trim((string) config('agt.homologation_fixture.alias')));
        $taxIdentificationNumber = Str::upper(
            preg_replace('/[^A-Za-z0-9]/', '', (string) config('agt.homologation_fixture.tax_identification_number')) ?? '',
        );
        $email = Str::lower(trim((string) config('agt.homologation_fixture.user_email')));
        $password = (string) config('agt.homologation_fixture.user_password');
        $testIdentities = config('agt.homologation_test_identities', []);

        if (! is_array($testIdentities) || ! isset($testIdentities[$alias]) || ! is_array($testIdentities[$alias])) {
            throw new InvalidArgumentException('The AGT homologation fixture alias must be between HML-T01 and HML-T05.');
        }

        $testIdentity = $testIdentities[$alias];
        $officialTaxIdentificationNumber = (string) ($testIdentity['tax_identification_number'] ?? '');
        $officialLegalName = (string) ($testIdentity['legal_name'] ?? '');

        if ($taxIdentificationNumber === '') {
            throw new InvalidArgumentException('Set AGT_HOMOLOGATION_FIXTURE_NIF from the controlled AGT test-identity workbook before running this seeder.');
        }

        if ($taxIdentificationNumber !== $officialTaxIdentificationNumber || $officialLegalName === '') {
            throw new InvalidArgumentException("AGT_HOMOLOGATION_FIXTURE_NIF must match the official AGT test identity configured for {$alias}.");
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('AGT_HOMOLOGATION_FIXTURE_EMAIL must be a valid email address.');
        }

        if (Str::length($password) < 12) {
            throw new InvalidArgumentException('AGT_HOMOLOGATION_FIXTURE_PASSWORD must contain at least 12 characters.');
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => "Operador {$alias}",
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ])->save();

        $workspace = Workspace::query()->updateOrCreate(
            ['slug' => 'agt-homologation-'.Str::lower($alias)],
            [
                'name' => "AGT Homologação {$alias}",
                'created_by_user_id' => $user->id,
            ],
        );

        WorkspaceMembership::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            [
                'role' => WorkspaceRole::Owner,
                'is_active' => true,
                'joined_at' => now(),
            ],
        );

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $legalEntity = LegalEntity::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'tax_identification_number' => $taxIdentificationNumber,
            ],
            [
                'legal_name' => $officialLegalName,
                'trade_name' => $alias,
                'tax_regime' => TaxRegime::General,
                'status' => LegalEntityStatus::Configured,
                'country_code' => 'AO',
                'currency_code' => 'AOA',
                'timezone' => 'Africa/Luanda',
                'onboarding_completed_at' => now(),
            ],
        );

        Establishment::query()->updateOrCreate(
            [
                'legal_entity_id' => $legalEntity->id,
                'code' => 'SEDE',
            ],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Sede de homologação',
                'address_line' => 'Morada reservada para homologação AGT',
                'municipality' => 'Luanda',
                'province_code' => Province::Luanda->value,
                'timezone' => 'Africa/Luanda',
                'is_head_office' => true,
                'is_active' => true,
            ],
        );
    }
}
