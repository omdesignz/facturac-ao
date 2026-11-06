<?php

use App\Actions\SignInMobileDevice;
use App\Models\CatalogueItem;
use App\Models\StockLevel;
use App\Models\User;
use App\WorkspaceRole;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function lookupToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

it('finds the item a barcode belongs to', function (): void {
    $company = posCompany();
    $item = posItem($company, ['barcode' => '5601234567890', 'name' => 'Arroz']);

    $this->withToken(lookupToken($company['owner']))->getJson(route('mobile.v1.items.lookup', ['code' => '5601234567890']))
        ->assertOk()
        ->assertJsonPath('data.item.public_id', $item->public_id)
        ->assertJsonPath('data.item.name', 'Arroz')
        ->assertJsonPath('data.item.unit_price_minor', 10_000);
});

it('matches exactly, so part of a code never lands on another item', function (): void {
    $company = posCompany();
    posItem($company, ['barcode' => '5601234567890']);

    $this->withToken(lookupToken($company['owner']))->getJson(route('mobile.v1.items.lookup', ['code' => '56012345']))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ITEM_NOT_FOUND')
        ->assertJsonPath('error.message', 'Nenhum artigo tem este código.');
});

it('also reads a label carrying the item\'s own code, but prefers a barcode', function (): void {
    $company = posCompany();
    $byCode = posItem($company, ['code' => 'ARR-25']);
    $byBarcode = posItem($company, ['code' => 'OUTRO', 'barcode' => 'ARR-25']);
    $other = posItem($company, ['code' => 'FEI-10']);
    $token = lookupToken($company['owner']);

    $this->withToken($token)->getJson(route('mobile.v1.items.lookup', ['code' => 'ARR-25']))
        ->assertJsonPath('data.item.public_id', $byBarcode->public_id);

    $this->withToken($token)->getJson(route('mobile.v1.items.lookup', ['code' => 'fei-10']))
        ->assertJsonPath('data.item.public_id', $other->public_id);

    expect($byCode->public_id)->not->toBe($byBarcode->public_id);
});

it('does not find an archived item, or another company\'s', function (): void {
    $company = posCompany();
    posItem($company, ['barcode' => 'ARCHIVED1', 'is_active' => false]);
    $token = lookupToken($company['owner']);
    $other = posCompany();
    posItem($other, ['barcode' => 'THEIRS1']);

    foreach (['ARCHIVED1', 'THEIRS1'] as $code) {
        $this->withToken($token)->getJson(route('mobile.v1.items.lookup', ['code' => $code]))->assertNotFound();
    }
});

it('says how much is in stock, wherever it is kept', function (): void {
    $company = posCompany();
    $item = posItem($company, ['barcode' => 'STOCK1', 'tracks_stock' => true]);
    StockLevel::query()->forceCreate([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'catalogue_item_id' => $item->id,
        'quantity_units' => 7,
        'quantity_scale' => 0,
    ]);

    $this->withToken(lookupToken($company['owner']))->getJson(route('mobile.v1.items.lookup', ['code' => 'STOCK1']))
        ->assertOk()
        ->assertJsonPath('data.tracks_stock', true)
        ->assertJsonPath('data.stock.0.quantity', '7')
        ->assertJsonPath('data.stock.0.establishment', 'Loja central');
});

it('lets a viewer look an item up', function (): void {
    $company = posCompany();
    posItem($company, ['barcode' => 'VIEW1']);

    $this->withToken(lookupToken(posMember($company, WorkspaceRole::Viewer)))
        ->getJson(route('mobile.v1.items.lookup', ['code' => 'VIEW1']))
        ->assertOk();
});

it('needs a code', function (): void {
    $company = posCompany();

    $this->withToken(lookupToken($company['owner']))->getJson(route('mobile.v1.items.lookup'))
        ->assertJsonValidationErrors('code');
});

it('makes a new item carrying the code the camera read, and a retry is the same item', function (): void {
    $company = posCompany();
    $body = [
        'client_key' => (string) Str::uuid(),
        'name' => 'Feijão',
        'type' => 'product',
        'unit_price' => '1500',
        'tax_treatment' => 'IVA_NOR_14',
        'barcode' => '5609999999999',
    ];
    $token = lookupToken($company['owner']);

    $this->withToken($token)->postJson(route('mobile.v1.items.store'), $body)->assertCreated();
    $this->withToken($token)->postJson(route('mobile.v1.items.store'), $body)->assertOk();

    expect(CatalogueItem::query()->where('barcode', '5609999999999')->count())->toBe(1);

    $this->withToken($token)->getJson(route('mobile.v1.items.lookup', ['code' => '5609999999999']))
        ->assertJsonPath('data.item.name', 'Feijão');
});

it('refuses a code another item already has', function (): void {
    $company = posCompany();
    posItem($company, ['barcode' => 'TAKEN1']);

    $this->withToken(lookupToken($company['owner']))->postJson(route('mobile.v1.items.store'), [
        'client_key' => (string) Str::uuid(),
        'name' => 'Feijão',
        'type' => 'product',
        'unit_price' => '1500',
        'tax_treatment' => 'IVA_NOR_14',
        'barcode' => 'TAKEN1',
    ])->assertJsonValidationErrors('barcode');
});
