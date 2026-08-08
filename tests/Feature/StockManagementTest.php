<?php

use App\Actions\RecordStockMovement;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\StockMovementType;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, item: CatalogueItem}
 */
function stockFixture(?int $reorderLevelUnits = null): array
{
    $owner = User::factory()->withWorkspace('VAP Armazém')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5000000042',
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $item = CatalogueItem::factory()->tracked($reorderLevelUnits)->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'code' => 'ART-0001',
        'name' => 'Cimento 50kg',
        'unit_of_measure' => 'UN',
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'item');
}

function record(
    array $fixture,
    StockMovementType $type,
    int $quantity,
    ?int $costMicros = null,
): StockMovement {
    return app(RecordStockMovement::class)->execute(
        item: $fixture['item'],
        establishment: $fixture['establishment'],
        type: $type,
        quantityUnits: $quantity,
        actor: $fixture['owner'],
        unitCostMicros: $costMicros,
    );
}

test('an opening balance creates the level and the ledger entry together', function () {
    $fixture = stockFixture();

    $movement = record($fixture, StockMovementType::Opening, 100_000, 5_000_000_000);

    $level = StockLevel::query()->sole();

    expect($level->quantity_units)->toBe(100_000)
        ->and($level->average_cost_micros)->toBe(5_000_000_000)
        ->and($movement->balance_after_units)->toBe(100_000)
        ->and($movement->quantity_units)->toBe(100_000);
});

test('an outbound movement is stored as a negative quantity', function () {
    $fixture = stockFixture();

    record($fixture, StockMovementType::Opening, 100_000, 1_000_000);
    $sale = record($fixture, StockMovementType::WriteOff, 30_000);

    expect($sale->quantity_units)->toBe(-30_000)
        ->and($sale->balance_after_units)->toBe(70_000)
        ->and(StockLevel::query()->sole()->quantity_units)->toBe(70_000);
});

test('the cost is a weighted average of what was paid', function () {
    $fixture = stockFixture();

    // 100 units at 10, then 100 at 20, should carry at 15.
    record($fixture, StockMovementType::Opening, 100_000, 10_000_000);
    record($fixture, StockMovementType::Purchase, 100_000, 20_000_000);

    expect(StockLevel::query()->sole()->average_cost_micros)->toBe(15_000_000);
});

test('taking stock out does not change what the rest cost', function () {
    $fixture = stockFixture();

    record($fixture, StockMovementType::Opening, 100_000, 10_000_000);
    record($fixture, StockMovementType::WriteOff, 50_000);

    expect(StockLevel::query()->sole()->average_cost_micros)->toBe(10_000_000);
});

test('an outbound movement is valued at the average, not at nothing', function () {
    $fixture = stockFixture();

    record($fixture, StockMovementType::Opening, 100_000, 10_000_000);
    $out = record($fixture, StockMovementType::WriteOff, 20_000);

    expect($out->unit_cost_micros)->toBe(10_000_000);
});

test('the balance can go negative rather than blocking the sale', function () {
    $fixture = stockFixture();

    $movement = record($fixture, StockMovementType::WriteOff, 5_000);

    expect($movement->balance_after_units)->toBe(-5_000)
        ->and(StockLevel::query()->sole()->quantity_units)->toBe(-5_000);
});

test('a purchase onto a negative balance prices from the incoming cost alone', function () {
    $fixture = stockFixture();

    record($fixture, StockMovementType::WriteOff, 10_000);
    record($fixture, StockMovementType::Purchase, 30_000, 7_000_000);

    // Averaging against stock that was not there would invent a cost.
    expect(StockLevel::query()->sole()->average_cost_micros)->toBe(7_000_000);
});

test('an item that does not track stock is refused', function () {
    $fixture = stockFixture();
    $fixture['item']->forceFill(['tracks_stock' => false])->save();

    expect(fn () => record($fixture, StockMovementType::Purchase, 1_000, 1_000_000))
        ->toThrow(RuntimeException::class);
});

test('a zero or negative quantity is refused', function () {
    $fixture = stockFixture();

    expect(fn () => record($fixture, StockMovementType::Purchase, 0, 1_000_000))
        ->toThrow(InvalidArgumentException::class);
});

test('an inbound movement without a cost is refused', function () {
    $fixture = stockFixture();

    expect(fn () => record($fixture, StockMovementType::Purchase, 1_000))
        ->toThrow(InvalidArgumentException::class);
});

test('stock cannot be moved into another company establishment', function () {
    $fixture = stockFixture();
    $otherOwner = User::factory()->withWorkspace('Outra')->create();
    $otherEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $otherOwner->currentWorkspace()->firstOrFail()->id,
        'tax_identification_number' => '5000000099',
    ]);
    $foreign = Establishment::factory()->headOffice()->create([
        'workspace_id' => $otherEntity->workspace_id,
        'legal_entity_id' => $otherEntity->id,
    ]);

    expect(fn () => app(RecordStockMovement::class)->execute(
        item: $fixture['item'],
        establishment: $foreign,
        type: StockMovementType::Purchase,
        quantityUnits: 1_000,
        unitCostMicros: 1_000_000,
    ))->toThrow(RuntimeException::class);
});

test('the value on hand is quantity times average cost', function () {
    $fixture = stockFixture();

    // 4 units carried at 250.00, so 1 000.00 in total.
    record($fixture, StockMovementType::Opening, 4_000, 25_000_000_000);

    expect(StockLevel::query()->sole()->valueMinor())->toBe(100_000);
});

test('a level at or below its minimum is flagged', function () {
    $fixture = stockFixture(reorderLevelUnits: 10_000);

    record($fixture, StockMovementType::Opening, 10_000, 1_000_000);

    expect(StockLevel::query()->sole()->isBelowReorderLevel())->toBeTrue();
});

test('a level above its minimum is not flagged', function () {
    $fixture = stockFixture(reorderLevelUnits: 10_000);

    record($fixture, StockMovementType::Opening, 10_001, 1_000_000);

    expect(StockLevel::query()->sole()->isBelowReorderLevel())->toBeFalse();
});

test('an item with no minimum is never flagged', function () {
    $fixture = stockFixture();

    record($fixture, StockMovementType::Opening, 1, 1_000_000);

    expect(StockLevel::query()->sole()->isBelowReorderLevel())->toBeFalse();
});

test('the stock page lists levels and the running total', function () {
    $fixture = stockFixture();
    record($fixture, StockMovementType::Opening, 4_000, 25_000_000_000);

    $this->actingAs($fixture['owner'])
        ->get(route('stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Stock/Index')
            ->where('summary.tracked_items', 1)
            ->where('summary.total_value_minor', 100_000)
            ->where('levels.0.code', 'ART-0001')
            ->where('levels.0.quantity', '4')
        );
});

test('a movement can be recorded through the interface', function () {
    $fixture = stockFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'type' => 'purchase',
            'quantity' => '12.5',
            'unit_cost' => '3000',
            'note' => 'Guia 88',
        ])
        ->assertRedirect();

    $level = StockLevel::query()->sole();

    // 12.5 units, and 3 000 kwanzas as micros of a minor unit.
    expect($level->quantity_units)->toBe(12_500)
        ->and($level->average_cost_micros)->toBe(300_000_000_000);
});

test('a cost typed in kwanzas values the stock in kwanzas', function () {
    $fixture = stockFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'type' => 'purchase',
            'quantity' => '40',
            'unit_cost' => '6500',
        ]);

    // 40 at 6 500,00 Kz is 260 000,00 Kz, or 26 000 000 minor units. Reading
    // the field at the wrong scale would report 2 600,00 and nobody would
    // notice until the stocktake.
    expect(StockLevel::query()->sole()->valueMinor())->toBe(26_000_000);
});

test('a decimal quantity survives without a float rounding it', function () {
    $fixture = stockFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'type' => 'opening',
            'quantity' => '1.005',
            'unit_cost' => '1',
        ]);

    expect(StockLevel::query()->sole()->quantity_units)->toBe(1_005);
});

test('a transfer moves stock between establishments as two entries', function () {
    $fixture = stockFixture();
    $destination = Establishment::factory()->create([
        'workspace_id' => $fixture['establishment']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'is_head_office' => false,
        'code' => 'EST2',
    ]);

    record($fixture, StockMovementType::Opening, 50_000, 4_000_000);

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'destination_establishment' => $destination->public_id,
            'type' => 'transfer_out',
            'quantity' => '20',
        ])
        ->assertRedirect();

    $origin = StockLevel::query()
        ->where('establishment_id', $fixture['establishment']->id)
        ->sole();
    $arrived = StockLevel::query()
        ->where('establishment_id', $destination->id)
        ->sole();

    expect($origin->quantity_units)->toBe(30_000)
        ->and($arrived->quantity_units)->toBe(20_000)
        // Moving between warehouses must not revalue the goods.
        ->and($arrived->average_cost_micros)->toBe(4_000_000);
});

test('a transfer to the same establishment is refused', function () {
    $fixture = stockFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'destination_establishment' => $fixture['establishment']->public_id,
            'type' => 'transfer_out',
            'quantity' => '5',
        ])
        ->assertSessionHasErrors('destination_establishment');
});

test('sales cannot be recorded by hand', function () {
    $fixture = stockFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'type' => 'sale',
            'quantity' => '5',
        ])
        ->assertSessionHasErrors('type');
});

test('another company cannot move this company stock', function () {
    $fixture = stockFixture();
    $intruder = User::factory()->withWorkspace('Intruso')->create();
    LegalEntity::factory()->configured()->create([
        'workspace_id' => $intruder->currentWorkspace()->firstOrFail()->id,
        'tax_identification_number' => '5000000077',
    ]);

    $this->actingAs($intruder)
        ->post(route('stock.movements.store'), [
            'catalogue_item' => $fixture['item']->public_id,
            'establishment' => $fixture['establishment']->public_id,
            'type' => 'purchase',
            'quantity' => '5',
            'unit_cost' => '10',
        ])
        ->assertNotFound();

    expect(StockLevel::query()->count())->toBe(0);
});
