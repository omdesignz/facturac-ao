<?php

use App\Actions\SignInMobileDevice;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;

/**
 * @return array{owner: User, token: string, legalEntity: LegalEntity, customer: Customer, item: CatalogueItem}
 */
function editingCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $owner = User::factory()->withWorkspace('Kwanza Mercantil')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    Establishment::factory()->headOffice()->create(['workspace_id' => $workspace->id, 'legal_entity_id' => $legalEntity->id]);

    if ($role !== WorkspaceRole::Owner) {
        WorkspaceMembership::query()->where('user_id', $owner->id)->where('workspace_id', $workspace->id)->update(['role' => $role]);
    }

    return [
        'owner' => $owner,
        'token' => $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'legalEntity' => $legalEntity,
        'customer' => Customer::factory()->create([
            'workspace_id' => $workspace->id,
            'legal_entity_id' => $legalEntity->id,
            'name' => 'Pastelaria Kianda',
            'tax_identification_number' => '5000937410',
            'email' => 'contas@kianda.ao',
            'payment_terms_days' => 30,
            'credit_limit_minor' => 50_000_000,
            'auto_send_documents' => true,
        ]),
        'item' => CatalogueItem::factory()->create([
            'workspace_id' => $workspace->id,
            'legal_entity_id' => $legalEntity->id,
            'code' => 'OLEO-5L',
            'name' => 'Óleo alimentar 5 L',
            'type' => 'product',
            'unit_price_minor' => 940_000,
            'tax_type' => 'IVA',
            'tax_code' => 'NOR',
            'tax_percentage' => '14.00',
            'tax_exemption_code' => null,
            'barcode' => null,
            'tracks_stock' => true,
        ]),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function editedCustomer(array $overrides = []): array
{
    return [
        'name' => 'Pastelaria Kianda, Lda.',
        'tax_identification_number' => '5000937410',
        'address_line' => 'Rua Rainha Ginga 12, Luanda',
        'email' => 'financeiro@kianda.ao',
        'phone' => '923 456 789',
        'payment_terms_days' => 15,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function editedItem(array $overrides = []): array
{
    return [
        'name' => 'Óleo de soja 5 L',
        'unit_price' => '9900.50',
        'tax_treatment' => 'IVA_NOR_14',
        'barcode' => '5601234567890',
        ...$overrides,
    ];
}

test('the customer profile says what can be changed', function () {
    $company = editingCompany();
    $company['customer']->update(['address_line' => 'Rua 1']);

    $this->withToken($company['token'])->getJson(route('mobile.v1.customers.show', $company['customer']->public_id))
        ->assertOk()
        ->assertJsonPath('data.address_line', 'Rua 1')
        ->assertJsonPath('data.can_edit', true);
});

test('a customer\'s details change from the phone and their terms stay as the web set them', function () {
    $company = editingCompany();

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.update', $company['customer']->public_id), editedCustomer())
        ->assertOk()
        ->assertJsonPath('data.name', 'Pastelaria Kianda, Lda.')
        ->assertJsonPath('data.address_line', 'Rua Rainha Ginga 12, Luanda')
        ->assertJsonPath('data.payment_terms_days', 15);

    $customer = $company['customer']->fresh();

    expect($customer->email)->toBe('financeiro@kianda.ao')
        ->and($customer->phone)->toBe('923 456 789')
        ->and($customer->credit_limit_minor)->toBe(50_000_000)
        ->and($customer->auto_send_documents)->toBeTrue()
        ->and($customer->is_active)->toBeTrue();
});

test('a NIF another customer has is refused, and the customer\'s own is not', function () {
    $company = editingCompany();
    Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'tax_identification_number' => '5417000000',
    ]);
    $url = route('mobile.v1.customers.update', $company['customer']->public_id);

    $this->withToken($company['token'])->patchJson($url, editedCustomer(['tax_identification_number' => '5417000000']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_identification_number' => 'Já existe um cliente com este NIF.']);

    $this->withToken($company['token'])->patchJson($url, editedCustomer())->assertOk();
});

test('a name and a NIF are required, and an email must be one', function () {
    $company = editingCompany();

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.update', $company['customer']->public_id), editedCustomer([
            'name' => '',
            'tax_identification_number' => '',
            'email' => 'not-an-email',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'tax_identification_number', 'email']);
});

test('an article opens with its tax and barcode, and changes from the phone', function () {
    $company = editingCompany();
    $id = $company['item']->public_id;

    $this->withToken($company['token'])->getJson(route('mobile.v1.items.show', $id))
        ->assertOk()
        ->assertJsonPath('data.code', 'OLEO-5L')
        ->assertJsonPath('data.tax_treatment', 'IVA_NOR_14')
        ->assertJsonPath('data.barcode', null)
        ->assertJsonPath('data.can_edit', true);

    $this->withToken($company['token'])->patchJson(route('mobile.v1.items.update', $id), editedItem(['tax_treatment' => 'IVA_ISE_M00']))
        ->assertOk()
        ->assertJsonPath('data.name', 'Óleo de soja 5 L')
        ->assertJsonPath('data.unit_price_minor', 990_050)
        ->assertJsonPath('data.tax_treatment', 'IVA_ISE_M00')
        ->assertJsonPath('data.barcode', '5601234567890');

    $item = $company['item']->fresh();

    expect($item->code)->toBe('OLEO-5L')
        ->and($item->tracks_stock)->toBeTrue()
        ->and($item->tax_percentage)->toBe('0.00')
        ->and($item->tax_exemption_code)->toBe('M00');
});

test('a barcode another article has is refused, and a blank one clears it', function () {
    $company = editingCompany();
    CatalogueItem::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'barcode' => '111222333',
    ]);
    $url = route('mobile.v1.items.update', $company['item']->public_id);

    $this->withToken($company['token'])->patchJson($url, editedItem(['barcode' => '111222333']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['barcode']);

    $this->withToken($company['token'])->patchJson($url, editedItem(['barcode' => '']))->assertOk()
        ->assertJsonPath('data.barcode', null);
});

test('a price or tax the phone cannot express is refused', function () {
    $company = editingCompany();
    $url = route('mobile.v1.items.update', $company['item']->public_id);

    $this->withToken($company['token'])->patchJson($url, editedItem(['unit_price' => '12,5', 'tax_treatment' => 'IVA_99']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unit_price', 'tax_treatment']);

    expect($company['item']->fresh()->unit_price_minor)->toBe(940_000);
});

test('a viewer reads but changes nothing', function () {
    $company = editingCompany(WorkspaceRole::Viewer);

    $this->withToken($company['token'])->getJson(route('mobile.v1.items.show', $company['item']->public_id))
        ->assertJsonPath('data.can_edit', false);
    $this->withToken($company['token'])->getJson(route('mobile.v1.customers.show', $company['customer']->public_id))
        ->assertJsonPath('data.can_edit', false);

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.update', $company['customer']->public_id), editedCustomer())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CUSTOMER_NOT_ALLOWED');
    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.items.update', $company['item']->public_id), editedItem())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ITEM_NOT_ALLOWED');

    expect($company['customer']->fresh()->name)->toBe('Pastelaria Kianda')
        ->and($company['item']->fresh()->name)->toBe('Óleo alimentar 5 L');
});

test('another company\'s customer or article is not found', function () {
    $company = editingCompany();
    $other = editingCompany();
    app('auth')->forgetGuards();

    $this->withToken($other['token'])
        ->patchJson(route('mobile.v1.customers.update', $company['customer']->public_id), editedCustomer(['tax_identification_number' => '5000000001']))
        ->assertNotFound();
    $this->withToken($other['token'])->getJson(route('mobile.v1.items.show', $company['item']->public_id))->assertNotFound();
    $this->withToken($other['token'])->patchJson(route('mobile.v1.items.update', $company['item']->public_id), editedItem())->assertNotFound();
});
