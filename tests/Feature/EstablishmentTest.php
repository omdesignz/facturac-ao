<?php

use App\Actions\RecordStockMovement;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Province;
use App\StockMovementType;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, headOffice: Establishment}
 */
function establishmentFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Locais')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $headOffice = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'province_code' => Province::Luanda->value,
    ]);

    return compact('owner', 'legalEntity', 'headOffice');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function establishmentPayload(array $overrides = []): array
{
    return [
        'code' => 'LOJA2',
        'name' => 'Loja do Kilamba',
        'address_line' => 'Rua 21 de Janeiro, n.º 4',
        'municipality' => 'Belas',
        'province_code' => Province::Luanda->value,
        'is_head_office' => false,
        'is_active' => true,
        ...$overrides,
    ];
}

// ------------------------------------------------------------------- creating

test('a company can keep more than the one place onboarding gave it', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('establishments.store'), establishmentPayload())
        ->assertRedirect();

    expect($fixture['legalEntity']->establishments()->count())->toBe(2)
        ->and(Establishment::query()->where('code', 'LOJA2')->sole())
        ->name->toBe('Loja do Kilamba');
});

test('two places in one company cannot share a code', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('establishments.store'), establishmentPayload([
            'code' => $fixture['headOffice']->code,
        ]))->assertSessionHasErrors('code');
});

test('the province comes from the list rather than being typed', function () {
    $fixture = establishmentFixture();

    // "LU" is the sort of thing the old free-text field accepted.
    $this->actingAs($fixture['owner'])
        ->post(route('establishments.store'), establishmentPayload([
            'province_code' => 'LU',
        ]))->assertSessionHasErrors('province_code');
});

// -------------------------------------------------------------- the provinces

test('the list carries the twenty-one provinces of the 2024 reform', function () {
    $offered = array_column(Province::options(), 'label');

    expect($offered)->toHaveCount(21)
        // Cuando Cubango was split in two.
        ->and($offered)->toContain('Cuando')
        ->and($offered)->toContain('Cubango')
        ->and($offered)->not->toContain('Cuando Cubango')
        // Carved out of Moxico and Luanda respectively.
        ->and($offered)->toContain('Moxico Leste')
        ->and($offered)->toContain('Icolo e Bengo')
        // The provinces they were carved from still exist.
        ->and($offered)->toContain('Moxico')
        ->and($offered)->toContain('Luanda');
});

test('the abolished province still reads and still saves', function () {
    $fixture = establishmentFixture();

    $branch = Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::CuandoCubango->value,
    ]);

    // Editing the address of a place recorded before the split must not force
    // a province change, nor silently rewrite one already on documents.
    $this->actingAs($fixture['owner'])
        ->put(route('establishments.update', $branch), establishmentPayload([
            'code' => $branch->code,
            'address_line' => 'Outra rua, n.º 9',
            'province_code' => Province::CuandoCubango->value,
        ]))->assertRedirect()->assertSessionHasNoErrors();

    expect($branch->fresh()->province_code)->toBe(Province::CuandoCubango->value)
        ->and($branch->fresh()->address_line)->toBe('Outra rua, n.º 9')
        ->and(Province::CuandoCubango->isCurrent())->toBeFalse();
});

test('the abolished province is never offered for a new address', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('establishments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'provinces',
                fn (Collection $provinces): bool => ! $provinces
                    ->pluck('value')
                    ->contains(Province::CuandoCubango->value),
            )
        );
});

test('a new place is created before it is made the head office', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('establishments.store'), establishmentPayload([
            'is_head_office' => true,
        ]))->assertRedirect();

    expect(Establishment::query()->where('code', 'LOJA2')->sole()->is_head_office)
        ->toBeTrue()
        ->and($fixture['headOffice']->fresh()->is_head_office)->toBeFalse();
});

// --------------------------------------------------------- the head office

test('only one place is the head office at a time', function () {
    $fixture = establishmentFixture();

    $second = Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::Benguela->value,
    ]);

    $second->makeHeadOffice();

    expect($second->fresh()->is_head_office)->toBeTrue()
        ->and($fixture['headOffice']->fresh()->is_head_office)->toBeFalse()
        ->and($fixture['legalEntity']->establishments()->where('is_head_office', true)->count())
        ->toBe(1);
});

test('the head office cannot be deactivated out from under the company', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->put(route('establishments.update', $fixture['headOffice']), establishmentPayload([
            'code' => $fixture['headOffice']->code,
            'is_head_office' => true,
            'is_active' => false,
        ]))->assertRedirect();

    // Every document names where it was issued; there has to be one at all times.
    expect($fixture['headOffice']->fresh()->is_active)->toBeTrue();
});

test('the head office cannot simply stop being the head office', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->put(route('establishments.update', $fixture['headOffice']), establishmentPayload([
            'code' => $fixture['headOffice']->code,
            'is_head_office' => false,
            'is_active' => true,
        ]))->assertRedirect();

    expect($fixture['headOffice']->fresh()->is_head_office)->toBeTrue();
});

test('the head office cannot be removed', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->delete(route('establishments.destroy', $fixture['headOffice']))
        ->assertRedirect();

    expect($fixture['headOffice']->fresh()->exists)->toBeTrue();
});

// -------------------------------------------------------------------- removing

test('an unused place is removed outright', function () {
    $fixture = establishmentFixture();

    $spare = Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::Huila->value,
    ]);

    $this->actingAs($fixture['owner'])
        ->delete(route('establishments.destroy', $spare))
        ->assertRedirect();

    expect(Establishment::query()->whereKey($spare->id)->exists())->toBeFalse();
});

test('a place that has issued is stood down rather than deleted', function () {
    $fixture = establishmentFixture();

    $branch = Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::Benguela->value,
    ]);

    FiscalDocument::factory()->create([
        'workspace_id' => $branch->workspace_id,
        'legal_entity_id' => $branch->legal_entity_id,
        'establishment_id' => $branch->id,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/1',
    ]);

    $this->actingAs($fixture['owner'])
        ->delete(route('establishments.destroy', $branch))
        ->assertRedirect();

    // The document has to keep resolving where it came from.
    expect($branch->fresh()->exists)->toBeTrue()
        ->and($branch->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------- where it shows up

test('a second place becomes an issue point on every document screen', function () {
    $fixture = establishmentFixture();

    Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Loja do Kilamba',
        'province_code' => Province::Luanda->value,
    ]);

    foreach (['invoices.create', 'quotes.create', 'recurring.index'] as $route) {
        $this->actingAs($fixture['owner'])
            ->get(route($route))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('establishments', 2)
            );
    }
});

test('a deactivated place stops being offered', function () {
    $fixture = establishmentFixture();

    Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::Luanda->value,
        'is_active' => false,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('establishments', 1));
});

test('stock is counted per place, not per company', function () {
    $fixture = establishmentFixture();

    $branch = Establishment::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'province_code' => Province::Benguela->value,
    ]);

    $item = CatalogueItem::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'tracks_stock' => true,
    ]);

    app(RecordStockMovement::class)->execute(
        item: $item,
        establishment: $branch,
        type: StockMovementType::Purchase,
        quantityUnits: 10_000,
        actor: $fixture['owner'],
        unitCostMicros: 1_000_000,
    );

    expect($branch->stockLevels()->count())->toBe(1)
        ->and($fixture['headOffice']->stockLevels()->count())->toBe(0);
});

// ------------------------------------------------------------ onboarding

test('onboarding offers the same provinces as the establishments screen', function () {
    $fixture = establishmentFixture();

    $onboarding = $this->actingAs($fixture['owner'])
        ->get(route('onboarding'))
        ->assertOk();

    $establishments = $this->actingAs($fixture['owner'])
        ->get(route('establishments.index'))
        ->assertOk();

    // Two screens editing the same field must not disagree about what it takes.
    expect($onboarding->viewData('page')['props']['provinces'])
        ->toBe($establishments->viewData('page')['props']['provinces']);
});

test('onboarding refuses a typed province code', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->put(route('onboarding.update'), [
            'legal_name' => 'Kwanza Mercantil, Lda.',
            'trade_name' => '',
            'tax_identification_number' => $fixture['legalEntity']->tax_identification_number,
            'tax_regime' => 'general',
            'main_cae_code' => '47111',
            'establishment_code' => 'SEDE',
            'establishment_name' => 'Sede',
            'address_line' => 'Rua Rei Katyavala',
            'municipality' => 'Luanda',
            'province_code' => 'LU',
        ])->assertSessionHasErrors('province_code');
});

test('onboarding accepts a province from the list', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->put(route('onboarding.update'), [
            'legal_name' => 'Kwanza Mercantil, Lda.',
            'trade_name' => '',
            'tax_identification_number' => $fixture['legalEntity']->tax_identification_number,
            'tax_regime' => 'general',
            'main_cae_code' => '47111',
            'establishment_code' => 'SEDE',
            'establishment_name' => 'Sede',
            'address_line' => 'Rua Rei Katyavala',
            'municipality' => 'Luanda',
            'province_code' => Province::IcoloEBengo->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

    expect($fixture['headOffice']->fresh()->province_code)
        ->toBe(Province::IcoloEBengo->value);
});

// --------------------------------------------------------------------- screen

test('the establishments screen lists every place with what depends on it', function () {
    $fixture = establishmentFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('establishments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Establishments/Index')
            ->has('establishments', 1)
            ->where('establishments.0.is_head_office', true)
            ->where('establishments.0.province_label', 'Luanda')
            // The head office is never deletable, whatever else is true.
            ->where('establishments.0.can_delete', false)
            ->has('provinces', 21)
        );
});
