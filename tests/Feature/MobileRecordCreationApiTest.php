<?php

use App\Actions\SignInMobileDevice;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\MobileCreatedRecord;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Str;

/**
 * @return array{owner: User, token: string, legalEntity: LegalEntity}
 */
function creationCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
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
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newCustomer(array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'name' => 'Pastelaria Kianda',
        'tax_identification_number' => '5000937410',
        'email' => 'contas@kianda.ao',
        'phone' => '+244 923 000 000',
        'payment_terms_days' => 30,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newItem(array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'name' => 'Óleo alimentar 5 L',
        'type' => 'product',
        'unit_price' => '9400.00',
        'tax_treatment' => 'IVA_NOR_14',
        ...$overrides,
    ];
}

it('creates a customer and answers with the row the list gives', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Pastelaria Kianda')
        ->assertJsonPath('data.tax_identification_number', '5000937410')
        ->assertJsonPath('data.outstanding_minor', 0)
        ->assertJsonPath('data.currency_code', $company['legalEntity']->currency_code);

    $customer = Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->sole();

    expect($customer->payment_terms_days)->toBe(30)
        ->and($customer->country_code)->toBe('AO')
        ->and($customer->is_active)->toBeTrue()
        ->and($customer->auto_send_documents)->toBeFalse();
});

it('comes back with the same customer when a request is retried', function (): void {
    $company = creationCompany();
    $body = newCustomer();

    $first = $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), $body)->assertCreated();
    $second = $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), $body)->assertOk();

    expect($second->json('data.public_id'))->toBe($first->json('data.public_id'))
        ->and(Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->count())->toBe(1)
        ->and(MobileCreatedRecord::query()->count())->toBe(1);
});

it('refuses a NIF another customer already has, in the web\'s words', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer())->assertCreated();

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer(['name' => 'Outra']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_identification_number' => 'Já existe um cliente com este NIF.']);
});

it('keeps the web\'s rules for the NIF and the e-mail', function (array $overrides, string $field): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->exists())->toBeFalse();
})->with([
    'short NIF' => [['tax_identification_number' => '123'], 'tax_identification_number'],
    'no NIF' => [['tax_identification_number' => ''], 'tax_identification_number'],
    'bad e-mail' => [['email' => 'não-é-email'], 'email'],
    'no name' => [['name' => ''], 'name'],
    'no key' => [['client_key' => ''], 'client_key'],
]);

it('ignores anything the phone is not asked for', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer([
        'credit_limit' => '5000',
        'auto_send_documents' => true,
        'is_active' => false,
    ]))->assertCreated();

    $customer = Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->sole();

    expect($customer->credit_limit_minor)->toBeNull()
        ->and($customer->auto_send_documents)->toBeFalse()
        ->and($customer->is_active)->toBeTrue();
});

it('does not let a viewer create customers or items', function (): void {
    $company = creationCompany(WorkspaceRole::Viewer);

    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CUSTOMER_NOT_ALLOWED');

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ITEM_NOT_ALLOWED');

    expect(Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->exists())->toBeFalse()
        ->and(CatalogueItem::query()->where('legal_entity_id', $company['legalEntity']->id)->exists())->toBeFalse();
});

it('creates a product, making its code from the name', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem())
        ->assertCreated()
        ->assertJsonPath('data.code', 'OLEO-ALIMENTAR-5-L')
        ->assertJsonPath('data.type_label', 'Produto')
        ->assertJsonPath('data.unit_of_measure', 'UN')
        ->assertJsonPath('data.unit_price_minor', 940000);

    $item = CatalogueItem::query()->where('legal_entity_id', $company['legalEntity']->id)->sole();

    expect($item->tax_type)->toBe('IVA')
        ->and($item->tax_code)->toBe('NOR')
        ->and((float) $item->tax_percentage)->toBe(14.0)
        ->and($item->tracks_stock)->toBeFalse()
        ->and($item->currency_code)->toBe($company['legalEntity']->currency_code);
});

it('numbers a made-up code that is already taken', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem())->assertCreated();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem())
        ->assertCreated()
        ->assertJsonPath('data.code', 'OLEO-ALIMENTAR-5-L-2');
});

it('refuses a code the person typed when it is already taken', function (): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem(['code' => 'OLE-5']))->assertCreated();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem(['code' => 'ole-5']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'Já existe um artigo com este código.']);
});

it('creates a service through the service command with its tax treatment', function (string $treatment, string $type, ?string $code, ?string $exemption): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem([
        'name' => 'Mão de obra',
        'type' => 'service',
        'tax_treatment' => $treatment,
    ]))->assertCreated()->assertJsonPath('data.type_label', 'Serviço');

    $item = CatalogueItem::query()->where('legal_entity_id', $company['legalEntity']->id)->sole();

    expect($item->tax_type)->toBe($type)
        ->and($item->tax_code)->toBe($code)
        ->and($item->tax_exemption_code)->toBe($exemption);
})->with([
    'normal' => ['IVA_NOR_14', 'IVA', 'NOR', null],
    'exempt' => ['IVA_ISE_M04', 'IVA', 'ISE', 'M04'],
    'not subject' => ['NS_M02', 'NS', null, 'M02'],
]);

it('comes back with the same item when a request is retried', function (): void {
    $company = creationCompany();
    $body = newItem(['code' => 'OLE-5']);

    $first = $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), $body)->assertCreated();
    $second = $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), $body)->assertOk();

    expect($second->json('data.public_id'))->toBe($first->json('data.public_id'))
        ->and(CatalogueItem::query()->where('legal_entity_id', $company['legalEntity']->id)->count())->toBe(1);
});

it('keeps the web\'s rules for the price and the tax treatment', function (array $overrides, string $field): void {
    $company = creationCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'comma in the price' => [['unit_price' => '12,50'], 'unit_price'],
    'no price' => [['unit_price' => ''], 'unit_price'],
    'price too large to store' => [['unit_price' => '999999999999999999'], 'unit_price'],
    'unknown treatment' => [['tax_treatment' => 'IVA_5'], 'tax_treatment'],
    'unknown type' => [['type' => 'gift'], 'type'],
]);

it('does not reach another company\'s records', function (): void {
    $company = creationCompany();
    $other = creationCompany();
    $body = newCustomer();

    $this->withToken($other['token'])->postJson(route('mobile.v1.customers.store'), $body)->assertCreated();
    auth()->forgetGuards();
    $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), $body)->assertCreated();

    expect(Customer::query()->where('legal_entity_id', $company['legalEntity']->id)->count())->toBe(1)
        ->and(Customer::query()->where('legal_entity_id', $other['legalEntity']->id)->count())->toBe(1);
});

it('lets a sale carry on with what was just created', function (): void {
    $company = creationCompany();

    $customer = $this->withToken($company['token'])->postJson(route('mobile.v1.customers.store'), newCustomer())->json('data.public_id');
    $item = $this->withToken($company['token'])->postJson(route('mobile.v1.items.store'), newItem())->json('data.public_id');

    $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.preview'), [
        'customer_public_id' => $customer,
        'lines' => [['catalogue_item_public_id' => $item, 'quantity' => '2']],
    ])
        ->assertOk()
        ->assertJsonPath('data.net_total_minor', 1880000)
        ->assertJsonPath('data.gross_total_minor', 2143200);
});

it('tells the app what this person may create, and the tax treatments to offer', function (): void {
    $this->withToken(creationCompany()['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_create_customers', true)
        ->assertJsonPath('data.can_create_items', true)
        ->assertJsonPath('data.tax_treatments.0.value', 'IVA_NOR_14')
        ->assertJsonPath('data.tax_treatments.0.label', 'IVA · taxa normal (14%)');
});

it('tells the app when this person may only look', function (): void {
    $this->withToken(creationCompany(WorkspaceRole::Viewer)['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_create_customers', false)
        ->assertJsonPath('data.can_create_items', false);
});
