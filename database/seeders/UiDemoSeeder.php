<?php

namespace Database\Seeders;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\LegalEntityStatus;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\Quote;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceSubscription;
use App\Province;
use App\TaxRegime;
use App\WorkspaceRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A busy, subscribed company for reviewing the app by hand, one user per role.
 * Local only: run with `php artisan db:seed --class=UiDemoSeeder`.
 */
class UiDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string PASSWORD = 'KiandaDemo!2026';

    /** @var array<string, WorkspaceRole> */
    private const array MEMBERS = [
        'dono@kianda.test' => WorkspaceRole::Owner,
        'admin@kianda.test' => WorkspaceRole::Administrator,
        'contabilista@kianda.test' => WorkspaceRole::Accountant,
        'cobrancas@kianda.test' => WorkspaceRole::Billing,
        'consulta@kianda.test' => WorkspaceRole::Viewer,
    ];

    /** @var list<string> */
    private const array CUSTOMER_NAMES = [
        'Mavinga & Filhos, Lda.', 'Cooperativa Kizomba Dancing', 'Farmácia Benfica', 'Oficina Rangel Auto',
        'Supermercado Talatona', 'Clínica Sagrada Esperança', 'Restaurante Ilha Azul', 'Construções Kwanza Sul',
        'Escola Primária Nova Vida', 'Transportes Lubango Express', 'Hotel Baía de Luanda', 'Padaria Maianga',
        'Papelaria Cazenga', 'Agro Huambo, S.A.', 'Pescas do Namibe', 'Ferragens Viana', 'Café Marginal',
        'Tecnologias Kilamba', 'Armazéns do Porto Amboim', 'Mercearia Dona Teresa', 'Lavandaria Alvalade',
        'Gráfica Sambizanga', 'Bombas Soyo', 'Seguros Cabinda Mútua', 'Imobiliária Miramar', 'Salão Beleza Negra',
        'Distribuidora Malanje', 'Consultório Dr. Neto', 'Peixaria Mussulo', 'Óptica Benguela',
    ];

    /** @var list<array{string, string, int}> */
    private const array ITEMS = [
        ['CAFE-001', 'Café Moído 500 g', 4_500_00], ['ARROZ-25', 'Arroz Agulha 25 kg', 28_000_00],
        ['OLEO-5L', 'Óleo Alimentar 5 L', 9_800_00], ['ACUC-50', 'Açúcar Branco 50 kg', 42_000_00],
        ['FARI-25', 'Farinha de Trigo 25 kg', 19_500_00], ['AGUA-12', 'Água Mineral 1,5 L (pack 12)', 3_200_00],
        ['SABAO-20', 'Sabão Azul (caixa 20)', 12_600_00], ['PAPEL-A4', 'Papel A4 80 g (resma)', 5_400_00],
        ['TONER-85', 'Toner Laser 85A', 38_000_00], ['CIM-50', 'Cimento 50 kg', 7_900_00],
        ['TINTA-20', 'Tinta Plástica 20 L', 46_500_00], ['CABO-25', 'Cabo Eléctrico 2,5 mm (rolo)', 31_000_00],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('UiDemoSeeder only runs locally.');
        }

        if (User::query()->where('email', 'dono@kianda.test')->exists()) {
            return;
        }

        $owner = $this->user('dono@kianda.test', 'Teresa Kiala');

        $workspace = Workspace::query()->create([
            'name' => 'Kianda Comercial',
            'slug' => 'kianda-comercial-demo',
            'created_by_user_id' => $owner->id,
        ]);

        foreach (self::MEMBERS as $email => $role) {
            $user = $email === 'dono@kianda.test' ? $owner : $this->user($email, ucfirst(strtok($email, '@')).' Kianda');

            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        $this->user('novo@kianda.test', 'Nuno Novo');
        $this->user('suporte@kianda.test', 'Sara Suporte')->forceFill(['is_support_staff' => true])->save();

        WorkspaceSubscription::query()->create([
            'workspace_id' => $workspace->id,
            'subscription_plan_id' => SubscriptionPlan::query()->where('code', 'avancado-annual')->firstOrFail()->id,
            'status' => 'active',
            'current_period_started_at' => now()->subMonth()->startOfDay(),
            'current_period_ends_at' => now()->addMonths(11)->startOfDay(),
        ]);

        $legalEntity = LegalEntity::query()->create([
            'workspace_id' => $workspace->id,
            'legal_name' => 'Kianda Comercial, Lda.',
            'trade_name' => 'Kianda Comercial',
            'tax_identification_number' => '5499000017',
            'tax_regime' => TaxRegime::General,
            'main_cae_code' => '46900',
            'status' => LegalEntityStatus::Configured,
            'country_code' => 'AO',
            'currency_code' => 'AOA',
            'timezone' => 'Africa/Luanda',
            'onboarding_completed_at' => now(),
        ]);

        $establishments = collect([['SEDE', 'Sede', true], ['LOJA', 'Loja Talatona', false]])
            ->map(fn (array $row): Establishment => Establishment::query()->create([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'code' => $row[0],
                'name' => $row[1],
                'address_line' => 'Rua Rainha Ginga, Luanda',
                'municipality' => 'Luanda',
                'province_code' => Province::Luanda->value,
                'timezone' => 'Africa/Luanda',
                'is_head_office' => $row[2],
                'is_active' => true,
            ]));

        PosRegister::factory()->create([
            'workspace_id' => $workspace->id,
            'legal_entity_id' => $legalEntity->id,
            'establishment_id' => $establishments[1]->id,
            'name' => 'Caixa 1',
        ]);

        $customers = collect(self::CUSTOMER_NAMES)->map(fn (string $name): Customer => Customer::factory()->create([
            'workspace_id' => $workspace->id,
            'legal_entity_id' => $legalEntity->id,
            'name' => $name,
            'address_line' => fake('pt_PT')->streetAddress().', Luanda',
            'email' => 'geral@'.str($name)->ascii()->lower()->replaceMatches('/[^a-z]+/', '').'.test',
        ]));

        foreach (self::ITEMS as [$code, $name, $price]) {
            CatalogueItem::factory()->tracked(10_000)->create([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'code' => $code,
                'name' => $name,
                'description' => null,
                'unit_of_measure' => 'UN',
                'unit_price_minor' => $price,
            ]);
        }

        for ($number = 1; $number <= 140; $number++) {
            $customer = $customers[($number * 7) % $customers->count()];
            $net = random_int(12, 900) * 1_000_00;
            $tax = (int) round($net * 0.14);
            $date = now()->subDays(max(0, 150 - $number));
            $isDraft = $number > 134;
            $type = $number % 17 === 0 ? FiscalDocumentType::InvoiceReceipt : FiscalDocumentType::Invoice;

            $document = FiscalDocument::factory()->create([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'establishment_id' => $establishments[$number % 5 === 0 ? 1 : 0]->id,
                'customer_id' => $customer->id,
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $owner->id,
                'document_type' => $type,
                'status' => $isDraft ? FiscalDocumentStatus::Draft : FiscalDocumentStatus::Issued,
                'document_no' => $isDraft ? null : "{$type->value} KIANDA2026/{$number}",
                'document_date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays(30)->toDateString(),
                'customer_name' => $customer->name,
                'customer_tax_identification_number' => $customer->tax_identification_number,
                'customer_address' => $customer->address_line,
                'net_total_minor' => $net,
                'tax_payable_minor' => $tax,
                'gross_total_minor' => $net + $tax,
                'system_entry_at' => $isDraft ? null : $date,
                'frozen_at' => $isDraft ? null : $date,
                'issued_at' => $isDraft ? null : $date,
            ]);

            [$code, $name, $price] = self::ITEMS[$number % count(self::ITEMS)];

            FiscalDocumentLine::factory()->create([
                'fiscal_document_id' => $document->id,
                'product_code' => $code,
                'product_description' => $name,
                'unit_price_base_minor' => $price,
                'base_amount_minor' => $net,
                'net_amount_minor' => $net,
                'tax_amount_minor' => $tax,
                'gross_amount_minor' => $net + $tax,
            ]);
        }

        foreach ($customers->take(8) as $index => $customer) {
            Quote::factory()->create([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'establishment_id' => $establishments[0]->id,
                'customer_id' => $customer->id,
                'created_by_user_id' => $owner->id,
                'customer_name' => $customer->name,
                'reference' => 'ORC 2026/'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function user(string $email, string $name): User
    {
        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill([
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ])->save();

        return $user;
    }
}
