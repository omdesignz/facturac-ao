<?php

namespace Database\Seeders;

use App\LegalEntityStatus;
use App\Models\Customer;
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

class PhaseOneDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => 'demo@vap.ao']);
        $user->forceFill([
            'name' => 'Ana Manuel',
            'email_verified_at' => now(),
            'password' => Hash::make('VapInvoice!2026'),
        ])->save();

        $workspace = Workspace::query()->updateOrCreate(
            ['slug' => 'kwanza-mercantil-demo'],
            [
                'name' => 'Kwanza Mercantil',
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
                'tax_identification_number' => '5412345678',
            ],
            [
                'legal_name' => 'Kwanza Mercantil, Lda.',
                'trade_name' => 'Kwanza Mercantil',
                'tax_regime' => TaxRegime::General,
                'main_cae_code' => '46900',
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
                'name' => 'Sede',
                'address_line' => 'Rua Rei Katyavala, Luanda',
                'municipality' => 'Luanda',
                'province_code' => Province::Luanda->value,
                'timezone' => 'Africa/Luanda',
                'is_head_office' => true,
                'is_active' => true,
            ],
        );

        Customer::query()->updateOrCreate(
            [
                'legal_entity_id' => $legalEntity->id,
                'tax_identification_number' => '5411111111',
            ],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Mavinga & Filhos, Lda.',
                'country_code' => 'AO',
                'address_line' => 'Rua Rei Katyavala, n.º 88, Luanda',
                'email' => 'financeiro@mavinga.test',
                'phone' => '+244 923 111 222',
                'is_active' => true,
            ],
        );

        Customer::query()->updateOrCreate(
            [
                'legal_entity_id' => $legalEntity->id,
                'tax_identification_number' => '5000000123',
            ],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Cooperativa Kizomba Dancing',
                'country_code' => 'AO',
                'address_line' => 'Bairro Futungo de Belas, Rua 9, Luanda',
                'email' => 'geral@kizomba.test',
                'phone' => '+244 924 741 714',
                'is_active' => true,
            ],
        );
    }
}
