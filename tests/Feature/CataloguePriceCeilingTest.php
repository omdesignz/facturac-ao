<?php

use App\Models\CatalogueItem;

require_once __DIR__.'/../PosFixtures.php';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pricedArticle(array $overrides = []): array
{
    return [
        'code' => 'PRECO-1',
        'type' => 'product',
        'name' => 'Gerador industrial',
        'unit_of_measure' => 'UN',
        'unit_price' => '250.00',
        'tax_type' => 'IVA',
        'tax_percentage' => '14.00',
        'is_active' => true,
        'tracks_stock' => false,
        ...$overrides,
    ];
}

test('a price too large to store is refused in words, whatever the article is', function (string $type, string $price) {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), pricedArticle(['type' => $type, 'unit_price' => $price]))
        ->assertSessionHasErrors(['unit_price' => 'O preço não pode passar de 9 999 999 999 999,99.']);

    expect(CatalogueItem::query()->where('code', 'PRECO-1')->exists())->toBeFalse();
})->with([
    'product, the crash' => ['product', '999999999999999999'],
    'product, past the ceiling' => ['product', '10000000000000.00'],
    'service' => ['service', '999999999999999999'],
]);

test('an existing article cannot be given a price too large to store', function () {
    $company = posCompany();
    $item = posItem($company, ['code' => 'PRECO-1', 'unit_price_minor' => 25000]);

    $this->actingAs($company['owner'])
        ->put(route('catalogue.update', $item), pricedArticle(['unit_price' => '999999999999999999']))
        ->assertSessionHasErrors(['unit_price' => 'O preço não pode passar de 9 999 999 999 999,99.']);

    expect($item->fresh()->unit_price_minor)->toBe(25000);
});

test('the ceiling itself is a price', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), pricedArticle(['unit_price' => '9999999999999.99']))
        ->assertSessionHasNoErrors();

    expect(CatalogueItem::query()->where('code', 'PRECO-1')->sole()->unit_price_minor)->toBe(999_999_999_999_999);
});

test('the form still says when a price is not a number', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), pricedArticle(['unit_price' => 'muito']))
        ->assertSessionHasErrors('unit_price');
});
