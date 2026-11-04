<?php

use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\TransportDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('LIKE only differs from ILIKE on PostgreSQL; SQLite ignores case either way.');
    }

    expect(app()->environment())->toBe('testing')
        ->and(DB::getDriverName())->toBe('pgsql')
        ->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();

    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function pgSearchCompany(): array
{
    $owner = User::factory()->withWorkspace('VAP Maiúsculas')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

test('the palette finds documents and customers whatever case is typed', function () {
    $company = pgSearchCompany();
    Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Mavinga & Filhos, Lda.',
        'email' => 'Financeiro@Empresa.test',
    ]);
    FiscalDocument::factory()->issued()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'document_no' => 'FT DEMO2026/7',
        'customer_name' => 'Mavinga & Filhos, Lda.',
    ]);

    $this->actingAs($company['owner']);

    foreach (['mavinga', 'MAVINGA', 'Mavinga'] as $term) {
        $this->getJson(route('search', ['q' => $term]))
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(1, 'customers');
    }

    $this->getJson(route('search', ['q' => 'ft demo2026']))->assertJsonCount(1, 'documents');
    $this->getJson(route('search', ['q' => 'financeiro@empresa']))->assertJsonCount(1, 'customers');
    $this->getJson(route('search', ['q' => '%%']))->assertJsonCount(0, 'documents');
});

test('the customers list ignores case', function () {
    $company = pgSearchCompany();
    Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cooperativa Kizomba',
        'email' => 'Geral@Kizomba.test',
    ]);

    $this->actingAs($company['owner']);

    foreach (['kizomba', 'COOPERATIVA', 'geral@kizomba'] as $term) {
        $this->get(route('customers.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
    }
});

test('the catalogue and the stock page ignore case', function () {
    $company = pgSearchCompany();
    $item = CatalogueItem::factory()->tracked()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'code' => 'CAFE-001',
        'name' => 'Café Moído',
        'description' => 'Embalagem Grande',
    ]);
    StockLevel::query()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'catalogue_item_id' => $item->id,
        'establishment_id' => $company['establishment']->id,
        'quantity_units' => 1000,
        'quantity_scale' => 3,
        'average_cost_micros' => 0,
    ]);

    $this->actingAs($company['owner']);

    foreach (['cafe-001', 'CAFÉ', 'embalagem'] as $term) {
        $this->get(route('catalogue.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
    }

    foreach (['cafe-001', 'moído'] as $term) {
        $this->get(route('stock.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('levels', 1));
    }
});

test('the transport register ignores case', function () {
    $company = pgSearchCompany();
    TransportDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'recipient_name' => 'Armazéns do Porto',
        'vehicle_registration' => 'LD-12-34-AB',
    ]);

    $this->actingAs($company['owner']);

    foreach (['armazéns', 'PORTO', 'ld-12'] as $term) {
        $this->get(route('transport-documents.index', ['q' => $term]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));
    }
});

test('search ignores accents in both directions', function () {
    $company = pgSearchCompany();
    $joao = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'João Conceição',
    ]);
    $plain = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Mario Lopes',
    ]);

    $this->actingAs($company['owner']);

    foreach (['joao', 'JOAO', 'conceicao', 'Conceição'] as $term) {
        $this->getJson(route('search', ['q' => $term]))
            ->assertOk()
            ->assertJsonPath('customers.0.name', $joao->name);
    }

    $this->getJson(route('search', ['q' => 'Mário']))
        ->assertOk()
        ->assertJsonPath('customers.0.name', $plain->name);

    $this->get(route('customers.index', ['search' => 'joao']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.name', $joao->name));
});

test('the support console ignores case', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $customer = User::factory()->create(['name' => 'Ana Manuel', 'email' => 'Ana@Empresa.test']);

    $this->actingAs($staff);

    foreach (['ana manuel', 'ANA@EMPRESA'] as $term) {
        $this->get(route('support.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('results.0.email', $customer->email));
    }
});
