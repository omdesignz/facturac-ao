<?php

use App\Actions\RecordStockMovement;
use App\Actions\SignInMobileDevice;
use App\Models\CatalogueItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\StockMovementType;
use App\WorkspaceRole;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

/**
 * A shop with two counted items at its head office: 12 sacks of cement and
 * 4,5 kg of nails.
 *
 * @return array<string, mixed>
 */
function stocktakeCompany(): array
{
    $company = posCompany();
    $cement = CatalogueItem::factory()->tracked()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cimento 42,5 N',
        'code' => 'CIM-42',
        'unit_of_measure' => 'saco',
        'stock_scale' => 0,
    ]);
    $nails = CatalogueItem::factory()->tracked()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Pregos 5 cm',
        'code' => 'PRG-5',
        'unit_of_measure' => 'KG',
        'stock_scale' => 3,
    ]);

    foreach ([[$cement, 12, 0], [$nails, 4_500, 3]] as [$item, $units, $scale]) {
        StockLevel::query()->forceCreate([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
            'establishment_id' => $company['establishment']->id,
            'catalogue_item_id' => $item->id,
            'quantity_units' => $units,
            'quantity_scale' => $scale,
            'average_cost_micros' => 0,
        ]);
    }

    return [
        ...$company,
        'token' => $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'cement' => $cement,
        'nails' => $nails,
    ];
}

/**
 * Ten sacks found where twelve were expected, and 4,75 kg of nails.
 *
 * @param  array<string, mixed>  $company
 * @return array<string, mixed>
 */
function stocktakePayload(array $company, array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'establishment_public_id' => $company['establishment']->public_id,
        'lines' => [
            ['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '10'],
            ['catalogue_item_public_id' => $company['nails']->public_id, 'counted_quantity' => '4.75'],
        ],
        ...$overrides,
    ];
}

function stocktakeLevel(CatalogueItem $item): int
{
    return StockLevel::query()->where('catalogue_item_id', $item->id)->sole()->quantity_units;
}

test('the phone learns where it may count before it starts', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.stock.counts.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_count', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.establishments.0.public_id', $company['establishment']->public_id);
});

test('the review shows each difference and writes nothing', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.preview'), stocktakePayload($company))
        ->assertOk()
        ->assertJsonPath('data.lines.0.expected', '12')
        ->assertJsonPath('data.lines.0.counted', '10')
        ->assertJsonPath('data.lines.0.difference', '−2')
        ->assertJsonPath('data.lines.0.difference_sign', -1)
        ->assertJsonPath('data.lines.1.expected', '4,5')
        ->assertJsonPath('data.lines.1.counted', '4,75')
        ->assertJsonPath('data.lines.1.difference', '+0,25')
        ->assertJsonPath('data.changed', 2)
        ->assertJsonPath('data.unchanged', 0);

    expect(StockMovement::query()->count())->toBe(0)
        ->and(stocktakeLevel($company['cement']))->toBe(12);
});

test('confirming sets each balance to the count and keeps the difference as an acerto', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.store'), stocktakePayload($company, ['note' => 'Contagem de sexta']))
        ->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.changed', 2);

    expect(stocktakeLevel($company['cement']))->toBe(10)
        ->and(stocktakeLevel($company['nails']))->toBe(4_750);

    $cement = StockMovement::query()->where('catalogue_item_id', $company['cement']->id)->sole();

    expect($cement->type)->toBe(StockMovementType::Adjustment)
        ->and($cement->quantity_units)->toBe(-2)
        ->and($cement->balance_after_units)->toBe(10)
        ->and($cement->note)->toBe('Contagem de sexta')
        ->and($cement->created_by_user_id)->toBe($company['owner']->id);
});

test('a count that matches leaves no movement', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.store'), stocktakePayload($company, [
            'lines' => [['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '12']],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.changed', 0)
        ->assertJsonPath('data.unchanged', 1);

    expect(StockMovement::query()->count())->toBe(0);
});

test('sending the same count again corrects nothing more', function () {
    $company = stocktakeCompany();
    $payload = stocktakePayload($company);

    $this->withToken($company['token'])->postJson(route('mobile.v1.stock.counts.store'), $payload)->assertCreated();
    $this->withToken($company['token'])->postJson(route('mobile.v1.stock.counts.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect(StockMovement::query()->count())->toBe(2)
        ->and(stocktakeLevel($company['cement']))->toBe(10);
});

test('a sale between counting and confirming is not counted twice', function () {
    $company = stocktakeCompany();
    app(RecordStockMovement::class)->execute($company['cement'], $company['establishment'], StockMovementType::WriteOff, 3);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.store'), stocktakePayload($company))
        ->assertCreated();

    // Nine were left after the write-off; ten were found, so the acerto is +1.
    expect(stocktakeLevel($company['cement']))->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::Adjustment)->where('catalogue_item_id', $company['cement']->id)->sole()->quantity_units)->toBe(1);
});

test('a count with more decimals than the item keeps is refused before anything is written', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.store'), stocktakePayload($company, [
            'lines' => [
                ['catalogue_item_public_id' => $company['nails']->public_id, 'counted_quantity' => '4'],
                ['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '10.5'],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.1.counted_quantity']);

    expect(StockMovement::query()->count())->toBe(0);
});

test('an item that does not track stock, or the same item twice, is refused', function (Closure $lines) {
    $company = stocktakeCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.preview'), stocktakePayload($company, ['lines' => $lines($company)]))
        ->assertUnprocessable();
})->with([
    'untracked' => fn () => fn (array $company) => [[
        'catalogue_item_public_id' => posItem($company)->public_id,
        'counted_quantity' => '1',
    ]],
    'twice' => fn () => fn (array $company) => [
        ['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '1'],
        ['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '2'],
    ],
    'negative' => fn () => fn (array $company) => [
        ['catalogue_item_public_id' => $company['cement']->public_id, 'counted_quantity' => '-1'],
    ],
]);

test('a viewer may not count', function () {
    $company = stocktakeCompany();
    $company['owner']->workspaceMemberships()->update(['role' => WorkspaceRole::Viewer]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.stock.counts.setup'))
        ->assertJsonPath('data.can_count', false);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.stock.counts.store'), stocktakePayload($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'STOCK_COUNT_NOT_ALLOWED');

    expect(stocktakeLevel($company['cement']))->toBe(12);
});

test('the item list says which items are counted', function () {
    $company = stocktakeCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.items.index', ['q' => 'CIM-42']))
        ->assertOk()
        ->assertJsonPath('data.0.tracks_stock', true);
});
