<?php

use App\Models\CatalogueItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function barcodePayload(array $overrides = []): array
{
    return [
        'code' => 'BAR-001',
        'type' => 'product',
        'name' => 'Água 1,5 L',
        'unit_of_measure' => 'UN',
        'unit_price' => '250.00',
        'tax_type' => 'IVA',
        'tax_percentage' => '14.00',
        'is_active' => true,
        'tracks_stock' => false,
        ...$overrides,
    ];
}

test('a barcode is saved with the article and shown on the catalogue page', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), barcodePayload(['barcode' => ' 5601234567890 ']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CatalogueItem::query()->where('code', 'BAR-001')->sole()->barcode)->toBe('5601234567890');

    $this->actingAs($company['owner'])
        ->get(route('catalogue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('items.data.0.barcode', '5601234567890'));
});

test('a barcode can be added, changed and cleared on an existing article', function () {
    $company = posCompany();
    $item = posItem($company, ['code' => 'BAR-002', 'barcode' => null]);
    $payload = barcodePayload(['code' => 'BAR-002']);

    $this->actingAs($company['owner'])
        ->put(route('catalogue.update', $item), [...$payload, 'barcode' => 'AB-123'])
        ->assertSessionHasNoErrors();
    expect($item->fresh()->barcode)->toBe('AB-123');

    // Saving the same article again keeps its own barcode without tripping on itself.
    $this->actingAs($company['owner'])
        ->put(route('catalogue.update', $item), [...$payload, 'barcode' => 'AB-123'])
        ->assertSessionHasNoErrors();

    $this->actingAs($company['owner'])
        ->put(route('catalogue.update', $item), [...$payload, 'barcode' => ''])
        ->assertSessionHasNoErrors();
    expect($item->fresh()->barcode)->toBeNull();
});

test('a barcode is unique within a company', function () {
    $company = posCompany();
    posItem($company, ['code' => 'BAR-003', 'barcode' => '111222333']);

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), barcodePayload(['code' => 'BAR-004', 'barcode' => '111222333']))
        ->assertSessionHasErrors(['barcode' => 'Já existe um artigo com este código de barras.']);

    expect(CatalogueItem::query()->where('code', 'BAR-004')->exists())->toBeFalse();
});

test('two companies may use the same barcode', function () {
    $company = posCompany();
    $other = posCompany();
    posItem($other, ['barcode' => '111222333']);

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), barcodePayload(['barcode' => '111222333']))
        ->assertSessionHasNoErrors();

    expect(CatalogueItem::query()->where('barcode', '111222333')->count())->toBe(2);
});

test('articles without a barcode do not collide with each other', function () {
    $company = posCompany();

    foreach (['BAR-010', 'BAR-011', 'BAR-012'] as $code) {
        $this->actingAs($company['owner'])
            ->post(route('catalogue.store'), barcodePayload(['code' => $code]))
            ->assertSessionHasNoErrors();
    }

    expect(CatalogueItem::query()->whereNull('barcode')->count())->toBe(3);
});

test('the database refuses a repeated barcode in one company', function () {
    $company = posCompany();
    posItem($company, ['barcode' => 'DUP-1']);

    expect(fn () => posItem($company, ['barcode' => 'DUP-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a barcode is letters, digits and hyphens only, and not longer than 64', function (string $barcode) {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), barcodePayload(['barcode' => $barcode]))
        ->assertSessionHasErrors('barcode');
})->with(['has space', 'under_score', 'ponto.final', 'ç123', fn () => str_repeat('1', 65)]);

test('a service can carry a barcode too', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('catalogue.store'), barcodePayload([
            'code' => 'SERV-BAR',
            'type' => 'service',
            'barcode' => 'SERV-9',
        ]))
        ->assertSessionHasNoErrors();

    expect(CatalogueItem::query()->where('code', 'SERV-BAR')->sole())
        ->barcode->toBe('SERV-9')
        ->type->value->toBe('service');
});
