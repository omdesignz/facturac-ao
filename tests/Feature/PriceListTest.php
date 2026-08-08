<?php

use App\Actions\ResolveCustomerPrices;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, item: CatalogueItem}
 */
function pricingFixture(int $listPriceMinor = 1_000_000): array
{
    $owner = User::factory()->withWorkspace('VAP Preços')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $item = CatalogueItem::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'unit_price_minor' => $listPriceMinor,
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'item');
}

/**
 * @param  array<string, mixed>  $fixture
 */
function makeList(array $fixture, string $name = 'Revenda', bool $isDefault = false): PriceList
{
    return PriceList::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => $name,
        'is_default' => $isDefault,
    ]);
}

function priceOn(PriceList $list, CatalogueItem $item, int $minor): PriceListItem
{
    return PriceListItem::factory()->create([
        'workspace_id' => $list->workspace_id,
        'legal_entity_id' => $list->legal_entity_id,
        'price_list_id' => $list->id,
        'catalogue_item_id' => $item->id,
        'unit_price_minor' => $minor,
    ]);
}

// ------------------------------------------------------------------ the lists

test('a tabela is created and starts empty', function () {
    $fixture = pricingFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('price-lists.store'), [
            'name' => 'Grossista',
            'description' => 'Quem compra em quantidade',
            'is_default' => false,
            'is_active' => true,
        ])->assertRedirect();

    $list = PriceList::query()->sole();

    expect($list->name)->toBe('Grossista')
        ->and($list->currency_code)->toBe($fixture['legalEntity']->currency_code)
        ->and($list->items()->count())->toBe(0);
});

test('two tabelas cannot share a name within one company', function () {
    $fixture = pricingFixture();
    makeList($fixture, 'Revenda');

    $this->actingAs($fixture['owner'])
        ->post(route('price-lists.store'), [
            'name' => 'Revenda',
            'is_default' => false,
            'is_active' => true,
        ])->assertSessionHasErrors('name');
});

test('only one tabela is the default at a time', function () {
    $fixture = pricingFixture();
    $first = makeList($fixture, 'Revenda', isDefault: true);
    $second = makeList($fixture, 'Grossista');

    $second->makeDefault();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('prices are saved for the whole tabela at once', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);

    $this->actingAs($fixture['owner'])
        ->post(route('price-lists.prices.store', $list), [
            'prices' => [[
                'catalogue_item' => $fixture['item']->public_id,
                'unit_price' => '8500.00',
                'note' => null,
            ]],
        ])->assertRedirect();

    expect($list->items()->sole()->unit_price_minor)->toBe(850_000);
});

test('clearing a price takes the article off the tabela rather than zeroing it', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);
    priceOn($list, $fixture['item'], 850_000);

    $this->actingAs($fixture['owner'])
        ->post(route('price-lists.prices.store', $list), [
            'prices' => [[
                'catalogue_item' => $fixture['item']->public_id,
                'unit_price' => '',
                'note' => null,
            ]],
        ])->assertRedirect();

    // Charging nothing and not being on the list are different answers.
    expect($list->items()->count())->toBe(0);
});

// ------------------------------------------------------ what a customer pays

test('a customer on a tabela pays the tabela price', function () {
    $fixture = pricingFixture(listPriceMinor: 1_000_000);
    $list = makeList($fixture);
    priceOn($list, $fixture['item'], 850_000);

    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    expect(app(ResolveCustomerPrices::class)->forCustomer($customer))
        ->toBe([$fixture['item']->public_id => 850_000]);
});

test('a price agreed with one customer beats their tabela', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);
    priceOn($list, $fixture['item'], 850_000);

    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    CustomerPrice::factory()->create([
        'workspace_id' => $customer->workspace_id,
        'legal_entity_id' => $customer->legal_entity_id,
        'customer_id' => $customer->id,
        'catalogue_item_id' => $fixture['item']->id,
        'unit_price_minor' => 700_000,
    ]);

    expect(app(ResolveCustomerPrices::class)->forCustomer($customer))
        ->toBe([$fixture['item']->public_id => 700_000]);
});

test('a customer on no tabela is quoted nothing and falls to the catalogue', function () {
    $fixture = pricingFixture();

    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => null,
    ]);

    // Empty rather than the catalogue figure: the line is already filled from
    // the article, and repeating it here is how the two come to disagree.
    expect(app(ResolveCustomerPrices::class)->forCustomer($customer))->toBe([]);
});

test('an article missing from the tabela falls through to the catalogue', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);

    $other = CatalogueItem::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    priceOn($list, $other, 500_000);

    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    $resolved = app(ResolveCustomerPrices::class)->forCustomer($customer);

    expect($resolved)->toHaveKey($other->public_id)
        ->and($resolved)->not->toHaveKey($fixture['item']->public_id);
});

test('many customers resolve without a query each', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);
    priceOn($list, $fixture['item'], 850_000);

    $customers = Customer::factory()->count(5)->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    DB::enableQueryLog();
    $resolved = app(ResolveCustomerPrices::class)->forCustomers($customers);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One for the lists, one for the overrides — not two per customer.
    expect($queries)->toBe(2)
        ->and($resolved)->toHaveCount(5);
});

// ----------------------------------------------------------------- assignment

test('a new customer joins the default tabela without being told to', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture, 'Retalho', isDefault: true);

    $this->actingAs($fixture['owner'])
        ->post(route('customers.store'), [
            'name' => 'Padaria Cazenga',
            'tax_identification_number' => '5401234567',
            'country_code' => 'AO',
            'is_active' => true,
            'payment_terms_days' => 30,
            'auto_send_documents' => false,
        ])->assertRedirect();

    expect(Customer::query()->sole()->price_list_id)->toBe($list->id);
});

test('deleting a tabela leaves its customers on the catalogue price', function () {
    $fixture = pricingFixture();
    $list = makeList($fixture);

    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->delete(route('price-lists.destroy', $list))
        ->assertRedirect();

    expect($customer->fresh()->price_list_id)->toBeNull()
        ->and($customer->fresh()->is_active)->toBeTrue();
});

// --------------------------------------------------------------------- screen

test('the tabelas screen is reachable and lists every article to price', function () {
    $fixture = pricingFixture();
    makeList($fixture, 'Revenda');

    $this->actingAs($fixture['owner'])
        ->get(route('price-lists.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PriceLists/Index')
            ->has('lists', 1)
            ->where('selected.name', 'Revenda')
            // The whole catalogue, not only the priced rows: a blank line is a
            // real answer to "what do these customers pay for this".
            ->has('selected.rows', 1)
            ->where('selected.rows.0.unit_price', '')
        );
});

test('the invoice builder offers the tabela price', function () {
    $fixture = pricingFixture(listPriceMinor: 1_000_000);
    $list = makeList($fixture);
    priceOn($list, $fixture['item'], 850_000);

    Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'price_list_id' => $list->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($fixture) {
            $customer = collect($page->toArray()['props']['customers'])->first();

            expect($customer['agreed_prices'][$fixture['item']->public_id])
                ->toBe('8500.00');
        });
});
