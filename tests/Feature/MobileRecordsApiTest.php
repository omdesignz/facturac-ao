<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\User;

/**
 * @return array{owner: User, token: string, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function recordsCompany(string $name = 'Kwanza Mercantil'): array
{
    $owner = User::factory()->withWorkspace($name)->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Mavinga & Filhos',
        'email' => 'contas@mavinga.ao',
    ]);

    return [
        'owner' => $owner,
        'token' => $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'legalEntity' => $legalEntity,
        'establishment' => $establishment,
        'customer' => $customer,
    ];
}

/**
 * @param  array{owner: User, token: string, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}  $company
 * @param  array<string, mixed>  $overrides
 */
function recordsDocument(array $company, int $daysLate = 5, array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => $company['customer']->id,
        'customer_name' => $company['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(1, 9999),
        'document_date' => now()->subDays($daysLate + 30)->toDateString(),
        'due_date' => now()->subDays($daysLate)->toDateString(),
        'gross_total_minor' => 1_000_000,
        ...$overrides,
    ]);
}

test('documentos lists the register, newest first, a page at a time', function () {
    $company = recordsCompany();
    recordsDocument($company, 5, ['document_no' => 'FT 2026/1', 'document_date' => now()->subDays(9)->toDateString()]);
    recordsDocument($company, 5, ['document_no' => 'FT 2026/2', 'document_date' => now()->subDays(2)->toDateString()]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.document_no', 'FT 2026/2')
        ->assertJsonPath('data.0.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.total', 2);
});

test('documentos finds by number and by customer', function () {
    $company = recordsCompany();
    recordsDocument($company, 5, ['document_no' => 'FT 2026/1']);
    recordsDocument($company, 5, ['document_no' => 'FR 2026/9', 'customer_name' => 'Cliente final']);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.index', ['q' => 'FR 2026']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_no', 'FR 2026/9');

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.index', ['q' => 'mavinga']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_no', 'FT 2026/1');
});

test('a document tells the phone what is owed, how to share it and whether to remind', function () {
    $company = recordsCompany();
    $document = recordsDocument($company, 12, ['first_opened_by_customer_at' => now()->subDay(), 'open_count' => 1]);

    $response = $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $document->public_id))
        ->assertOk()
        ->assertJsonPath('data.document_no', $document->document_no)
        ->assertJsonPath('data.type_label', FiscalDocumentType::Invoice->label())
        ->assertJsonPath('data.outstanding_minor', 1_000_000)
        ->assertJsonPath('data.days_past_due', 12)
        ->assertJsonPath('data.can_remind', true)
        ->assertJsonPath('data.customer_public_id', $company['customer']->public_id)
        ->assertJsonPath('data.opened_by_customer', true)
        ->assertJsonPath('data.history.0.label', 'Aberta pelo cliente');

    $shareUrl = $response->json('data.share_url');

    expect($shareUrl)->toContain('signature=');

    auth()->forgetGuards();

    $this->get($shareUrl)->assertSuccessful();
});

test('a draft has no number, so nothing to share and nobody to remind', function () {
    $company = recordsCompany();
    $draft = recordsDocument($company, -10, [
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $draft->public_id))
        ->assertOk()
        ->assertJsonPath('data.document_no', null)
        ->assertJsonPath('data.share_url', null)
        ->assertJsonPath('data.can_remind', false);
});

test('another company\'s records never reach the token', function () {
    $company = recordsCompany();
    $document = recordsDocument($company);
    $stranger = recordsCompany('Outra Empresa');

    $this->withToken($stranger['token'])->getJson(route('mobile.v1.documents.index'))
        ->assertJsonPath('meta.total', 0);
    $this->withToken($stranger['token'])->getJson(route('mobile.v1.documents.show', $document->public_id))
        ->assertNotFound();
    $this->withToken($stranger['token'])->getJson(route('mobile.v1.customers.index'))
        ->assertJsonMissing(['public_id' => $company['customer']->public_id])
        ->assertJsonPath('meta.total', 1);
});

test('clientes says what each customer owes', function () {
    $company = recordsCompany();
    recordsDocument($company);

    $this->withToken($company['token'])->getJson(route('mobile.v1.customers.index', ['q' => 'MAVINGA']))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Mavinga & Filhos')
        ->assertJsonPath('data.0.outstanding_minor', 1_000_000);

    $this->withToken($company['token'])->getJson(route('mobile.v1.customers.index', ['q' => 'ninguém']))
        ->assertJsonPath('meta.total', 0);
});

test('artigos and stock list what the company sells and holds', function () {
    $company = recordsCompany();
    $item = CatalogueItem::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cimento 42,5 N',
        'code' => 'CIM-42',
        'unit_of_measure' => 'saco',
        'tracks_stock' => true,
        'reorder_level_units' => 10,
        'stock_scale' => 0,
    ]);
    StockLevel::query()->forceCreate([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'catalogue_item_id' => $item->id,
        'quantity_units' => 6,
        'quantity_scale' => 0,
        'average_cost_micros' => 0,
    ]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.items.index', ['q' => 'cimento']))
        ->assertOk()
        ->assertJsonPath('data.0.code', 'CIM-42');

    $this->withToken($company['token'])->getJson(route('mobile.v1.stock.index', ['q' => 'cim']))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Cimento 42,5 N')
        ->assertJsonPath('data.0.quantity', '6')
        ->assertJsonPath('data.0.is_low', true);
});

test('the records need a token', function () {
    foreach (['documents.index', 'customers.index', 'items.index', 'stock.index'] as $route) {
        $this->getJson(route("mobile.v1.{$route}"))->assertUnauthorized();
    }
});

test('a search too long for the box is refused', function () {
    $company = recordsCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.index', ['q' => str_repeat('a', 121)]))
        ->assertUnprocessable();
});
