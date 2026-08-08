<?php

use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, workspace: Workspace, legal_entity: LegalEntity}
 */
function registersCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $user = User::factory()->withWorkspace('VAP Registos')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();

    WorkspaceMembership::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->update(['role' => $role]);

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    return ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity];
}

test('the customer register lists only the current workspace records', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Cliente Desta Empresa',
    ]);

    $other = registersCompany();
    Customer::factory()->create([
        'workspace_id' => $other['workspace']->id,
        'legal_entity_id' => $other['legal_entity']->id,
        'name' => 'Cliente De Outra Empresa',
    ]);

    $this->actingAs($user)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Cliente Desta Empresa')
        );
});

test('a customer can be created and appears in the register', function () {
    ['user' => $user] = registersCompany();

    $this->actingAs($user)
        ->post(route('customers.store'), [
            'name' => 'Kizomba Comércio, Lda.',
            'tax_identification_number' => '5417004856',
            'country_code' => 'AO',
            'address_line' => 'Rua Rainha Ginga, Luanda',
            'email' => 'geral@kizomba.test',
            'phone' => '+244 923 000 000',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Customer::query()->where('tax_identification_number', '5417004856')->exists())->toBeTrue();
});

test('two customers cannot share a NIF within the same company', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'tax_identification_number' => '5000000123',
    ]);

    $this->actingAs($user)
        ->post(route('customers.store'), [
            'name' => 'Duplicado',
            'tax_identification_number' => '5000000123',
            'country_code' => 'AO',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('tax_identification_number');
});

test('deleting a customer deactivates it instead of removing the record', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('customers.destroy', $customer))
        ->assertRedirect();

    // Issued documents still reference this customer, so the row must survive.
    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue()
        ->and($customer->fresh()->is_active)->toBeFalse();
});

test('a customer from another workspace cannot be edited', function () {
    ['user' => $user] = registersCompany();
    $other = registersCompany();

    $foreign = Customer::factory()->create([
        'workspace_id' => $other['workspace']->id,
        'legal_entity_id' => $other['legal_entity']->id,
        'name' => 'Intocável',
    ]);

    $this->actingAs($user)
        ->put(route('customers.update', $foreign), [
            'name' => 'Invadido',
            'tax_identification_number' => '5999999999',
            'country_code' => 'AO',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($foreign->fresh()->name)->toBe('Intocável');
});

test('a viewer may read the register but not change it', function () {
    ['user' => $user] = registersCompany(WorkspaceRole::Viewer);

    $this->actingAs($user)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    $this->actingAs($user)
        ->post(route('customers.store'), [
            'name' => 'Não permitido',
            'tax_identification_number' => '5123456789',
            'country_code' => 'AO',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('a catalogue item stores its price in minor units', function () {
    ['user' => $user] = registersCompany();

    $this->actingAs($user)
        ->post(route('catalogue.store'), [
            'code' => 'CONS-001',
            'type' => 'service',
            'name' => 'Consultoria fiscal',
            'unit_of_measure' => 'UN',
            'unit_price' => '850000.50',
            'tax_type' => 'IVA',
            'tax_percentage' => '14.00',
            'is_active' => true,
        ])
        ->assertRedirect();

    $item = CatalogueItem::query()->where('code', 'CONS-001')->firstOrFail();

    // 850000.50 must land as 85_000_050 cents, never as a float.
    expect($item->unit_price_minor)->toBe(85000050);
});

test('catalogue codes cannot repeat within a company', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'code' => 'REP-001',
    ]);

    $this->actingAs($user)
        ->post(route('catalogue.store'), [
            'code' => 'REP-001',
            'type' => 'product',
            'name' => 'Repetido',
            'unit_of_measure' => 'UN',
            'unit_price' => '10.00',
            'tax_type' => 'IVA',
            'tax_percentage' => '14.00',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('code');
});

test('the catalogue register can be searched and filtered by type', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Instalação eléctrica',
        'type' => 'service',
    ]);
    CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Cabo de rede',
        'type' => 'product',
    ]);

    $this->actingAs($user)
        ->get(route('catalogue.index', ['type' => 'product']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.name', 'Cabo de rede')
        );

    $this->actingAs($user)
        ->get(route('catalogue.index', ['search' => 'eléctrica']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
});

test('the invoice screen offers the active catalogue items', function () {
    ['user' => $user, 'workspace' => $workspace, 'legal_entity' => $legalEntity] = registersCompany();

    CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'code' => 'ACT-1',
        'name' => 'Artigo activo',
        'unit_price_minor' => 250000,
        'is_active' => true,
    ]);
    CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'code' => 'INA-1',
        'name' => 'Artigo inactivo',
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalogueItems', 1)
            ->where('catalogueItems.0.code', 'ACT-1')
            ->where('catalogueItems.0.unit_price', '2500.00')
        );
});
