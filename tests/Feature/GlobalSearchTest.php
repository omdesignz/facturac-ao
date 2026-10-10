<?php

use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function searchFixture(string $workspaceName = 'VAP Pesquisa'): array
{
    $owner = User::factory()->withWorkspace($workspaceName)->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

/**
 * @param  array{owner: User, legalEntity: LegalEntity, establishment: Establishment}  $fixture
 * @param  array<string, mixed>  $attributes
 */
function searchDocument(array $fixture, array $attributes = []): FiscalDocument
{
    return FiscalDocument::factory()->issued()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'document_no' => 'FT PESQ/'.fake()->unique()->numberBetween(1, 99_999),
        'customer_name' => 'Cliente Genérico, Lda.',
        ...$attributes,
    ]);
}

test('a document is found by its number, with its AGT state', function () {
    $fixture = searchFixture();
    $document = searchDocument($fixture, ['document_no' => 'FT LDA/417']);
    searchDocument($fixture, ['document_no' => 'FT LDA/9']);

    $this->actingAs($fixture['owner'])
        ->getJson(route('search', ['q' => 'LDA/417']))
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.public_id', $document->public_id)
        ->assertJsonPath('documents.0.document_no', 'FT LDA/417')
        ->assertJsonPath('documents.0.workflow_status', 'unknown');
});

test('customers are found by name or NIF, and documents by the customer they name', function () {
    $fixture = searchFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Mavinga & Filhos, Lda.',
        'tax_identification_number' => '5417028391',
    ]);
    searchDocument($fixture, ['customer_name' => 'Mavinga & Filhos, Lda.']);

    $this->actingAs($fixture['owner'])
        ->getJson(route('search', ['q' => 'mavinga']))
        ->assertOk()
        ->assertJsonPath('customers.0.public_id', $customer->public_id)
        ->assertJsonCount(1, 'documents');

    $this->actingAs($fixture['owner'])
        ->getJson(route('search', ['q' => '5417028']))
        ->assertOk()
        ->assertJsonPath('customers.0.name', 'Mavinga & Filhos, Lda.');
});

test('the search never reaches another company', function () {
    $mine = searchFixture('VAP Minha');
    $theirs = searchFixture('VAP Alheia');
    searchDocument($theirs, ['document_no' => 'FT ALHEIA/1', 'customer_name' => 'Alheia, S.A.']);
    Customer::factory()->create([
        'workspace_id' => $theirs['legalEntity']->workspace_id,
        'legal_entity_id' => $theirs['legalEntity']->id,
        'name' => 'Alheia, S.A.',
    ]);

    $this->actingAs($mine['owner'])
        ->getJson(route('search', ['q' => 'Alheia']))
        ->assertOk()
        ->assertExactJson(['documents' => [], 'customers' => []]);
});

test('wildcards in the query are taken literally', function () {
    $fixture = searchFixture();
    searchDocument($fixture, ['document_no' => 'FT LIT/1']);

    $this->actingAs($fixture['owner'])
        ->getJson(route('search', ['q' => '%%']))
        ->assertOk()
        ->assertJsonCount(0, 'documents');
});

test('a query needs two characters and a signed-in user', function () {
    $fixture = searchFixture();

    $this->actingAs($fixture['owner'])
        ->getJson(route('search', ['q' => 'F']))
        ->assertUnprocessable();

    auth()->logout();

    $this->getJson(route('search', ['q' => 'FT']))->assertUnauthorized();
});

test('the header search box is wired to the search endpoint', function () {
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));
    $palette = (string) file_get_contents(resource_path('js/components/GlobalSearch.vue'));

    expect($header)->toContain('<GlobalSearch />')
        ->and($palette)->toContain("import { search } from '@/routes';")
        ->and($palette)->toContain('http.get(search.url())');
});

test('the palette sets one create action apart by flag, not by its label', function () {
    $palette = (string) file_get_contents(resource_path('js/components/GlobalSearch.vue'));

    expect($palette)->toContain('primary: true')
        ->and($palette)->toContain('result.primary')
        ->and($palette)->not->toContain("result.label === 'Emitir factura'")
        ->and($palette)->not->toContain('ArrowRight');
});
