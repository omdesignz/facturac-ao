<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

beforeEach(function (): void {
    $this->company = posCompany();
    $this->entity = $this->company['legalEntity'];
    $this->customer = Customer::factory()->create([
        'workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id,
        'payment_terms_days' => 10,
    ]);
    $this->item = posItem($this->company);
    $this->source = FiscalDocument::factory()->create([
        'workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id,
        'establishment_id' => $this->company['establishment']->id, 'customer_id' => $this->customer->id,
        'document_type' => FiscalDocumentType::InvoiceReceipt,
        'document_no' => 'FR TEST/1', 'status' => FiscalDocumentStatus::Issued,
    ]);
    FiscalDocumentLine::withoutEvents(fn () => FiscalDocumentLine::factory()->create([
        'fiscal_document_id' => $this->source->id, 'product_code' => $this->item->code,
        'unit_of_measure' => $this->item->unit_of_measure, 'quantity_units' => 20_000,
        'unit_price_base_minor' => 500, 'discount_rate_basis_points' => 1000,
    ]));
    $this->token = $this->company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
});

test('web prepares a fresh invoice with current customer prices and taxes without writing', function (): void {
    CustomerPrice::factory()->create([
        'workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id,
        'customer_id' => $this->customer->id, 'catalogue_item_id' => $this->item->id, 'unit_price_minor' => 7500,
    ]);
    $this->item->update(['tax_type' => 'IVA', 'tax_code' => 'ISE', 'tax_percentage' => '0', 'tax_exemption_code' => 'M04']);
    $before = $this->source->fresh()->getAttributes();
    $number = $this->company['series']->next_number;
    $this->actingAs($this->company['owner'])->get(route('invoices.create', ['repeat' => $this->source->public_id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Invoices/Create')
        ->where('repeatSource.document_no', 'FR TEST/1')->where('document.public_id', null)
        ->where('document.document_no', null)->where('document.document_type', 'FT')
        ->where('document.customer_public_id', $this->customer->public_id)
        ->where('document.document_date', now('Africa/Luanda')->toDateString())
        ->where('document.due_date', now('Africa/Luanda')->addDays(10)->toDateString())
        ->where('document.lines.0.quantity', '2')->where('document.lines.0.unit_price', '75.00')
        ->where('document.lines.0.tax_treatment', 'IVA_ISE_M04')->where('document.lines.0.discount_percentage', '0')
        ->where('document.lines.0.operation_date', now('Africa/Luanda')->toDateString())->where('document.references_document_public_id', null)
        ->where('document.settlements', [])->where('document.withholdings', []));
    expect($this->source->fresh()->getAttributes())->toBe($before);
    expect(FiscalDocument::count())->toBe(1);
    expect($this->company['series']->fresh()->next_number)->toBe($number);
});

test('phone prepares an uncached seed and merges duplicate articles without creating anything', function (): void {
    FiscalDocumentLine::withoutEvents(fn () => FiscalDocumentLine::factory()->create([
        'fiscal_document_id' => $this->source->id, 'line_number' => 2, 'product_code' => $this->item->code,
        'unit_of_measure' => $this->item->unit_of_measure, 'quantity_units' => 30_000,
    ]));
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', $this->source->public_id))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.source_public_id', $this->source->public_id)
        ->assertJsonPath('data.customer.public_id', $this->customer->public_id)
        ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.quantity', 5)
        ->assertJsonPath('data.lines.0.item.public_id', $this->item->public_id);
    expect(FiscalDocument::count())->toBe(1);
});

test('unavailable or changed catalogue entries refuse the entire repeat', function (string $change): void {
    match ($change) {
        'inactive' => $this->item->update(['is_active' => false]),
        'unit' => $this->item->update(['unit_of_measure' => 'kg']),
        'currency' => $this->item->update(['currency_code' => 'USD']),
        'code' => $this->item->update(['code' => 'NEW-CODE']),
        'tax' => $this->item->update(['tax_code' => 'NOR', 'tax_percentage' => '5']),
        'customer' => $this->customer->update(['is_active' => false]),
    };
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', $this->source->public_id))
        ->assertUnprocessable()->assertJsonValidationErrors('repeat');
    $this->actingAs($this->company['owner'])->get(route('invoices.create', ['repeat' => $this->source->public_id]))
        ->assertRedirect(route('documents.index', ['documento' => $this->source->public_id]))->assertSessionHas('error');
})->with(['inactive', 'unit', 'currency', 'code', 'tax', 'customer']);

test('fractional quantities remain exact on web and are refused on older phones', function (): void {
    FiscalDocumentLine::withoutEvents(fn () => $this->source->lines()->first()->update(['quantity_units' => 12_345]));
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', $this->source->public_id))
        ->assertUnprocessable()->assertJsonValidationErrors('repeat');
    $this->actingAs($this->company['owner'])->get(route('invoices.create', ['repeat' => $this->source->public_id]))
        ->assertInertia(fn (Assert $page) => $page->where('document.lines.0.quantity', '1.2345'));
});

test('repeat is scoped to the current company and requires permission', function (): void {
    $foreign = FiscalDocument::factory()->create();
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', $foreign->public_id))->assertNotFound();
    $this->withToken($this->token)->withHeader('X-Company', 'other-company')
        ->getJson(route('mobile.v1.invoices.repeat', $this->source->public_id))->assertConflict();
    $this->flushHeaders();
    $viewer = User::factory()->create(['current_workspace_id' => $this->entity->workspace_id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->entity->workspace_id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
    $this->actingAs($viewer)->get(route('invoices.create', ['repeat' => $this->source->public_id]))->assertForbidden();
});

test('drafts invalid documents and adjustments cannot be reused as sales', function (string $kind): void {
    FiscalDocument::withoutEvents(fn () => $this->source->forceFill(match ($kind) {
        'draft' => ['status' => FiscalDocumentStatus::Draft],
        'invalid' => ['status' => FiscalDocumentStatus::Invalid],
        'credit' => ['document_type' => FiscalDocumentType::CreditNote],
        'anonymous' => ['customer_id' => null],
    })->save());
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', $this->source->public_id))
        ->assertUnprocessable()->assertJsonValidationErrors('repeat');
})->with(['draft', 'invalid', 'credit', 'anonymous']);

test('the original customer and articles remain selectable beyond picker limits', function (): void {
    $this->customer->update(['name' => 'ZZZ Original']);
    $this->item->update(['name' => 'ZZZ Original']);
    Customer::factory()->count(250)->create([
        'workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id, 'name' => 'AAA Customer',
    ]);
    CatalogueItem::factory()->count(500)->create([
        'workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id, 'name' => 'AAA Article',
    ]);
    $this->actingAs($this->company['owner'])->get(route('invoices.create', ['repeat' => $this->source->public_id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('customers', fn ($rows) => collect($rows)->contains('public_id', $this->customer->public_id))
        ->where('catalogueItems', fn ($rows) => collect($rows)->contains('public_id', $this->item->public_id)));
});

test('decimal capable phones combine fractional quantities exactly while web keeps separate lines', function (): void {
    FiscalDocumentLine::withoutEvents(fn () => $this->source->lines()->first()->update(['quantity_units' => 12_345]));
    FiscalDocumentLine::withoutEvents(fn () => FiscalDocumentLine::factory()->create([
        'fiscal_document_id' => $this->source->id, 'line_number' => 2, 'product_code' => $this->item->code,
        'unit_of_measure' => $this->item->unit_of_measure, 'quantity_units' => 2, 'quantity_scale' => 1,
    ]));
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', [
        'documentPublicId' => $this->source->public_id, 'quantity_format' => 'decimal',
    ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.quantity', '1.4345');
    $this->actingAs($this->company['owner'])->get(route('invoices.create', ['repeat' => $this->source->public_id]))
        ->assertInertia(fn (Assert $page) => $page->has('document.lines', 2)
            ->where('document.lines.0.quantity', '1.2345')->where('document.lines.1.quantity', '0.2'));
    expect(FiscalDocument::count())->toBe(1);
});

test('decimal repeat refuses overflow before adding or rescaling quantities', function (int $units, int $scale): void {
    FiscalDocumentLine::withoutEvents(fn () => $this->source->lines()->first()->update([
        'quantity_units' => PHP_INT_MAX - 1, 'quantity_scale' => 0,
    ]));
    FiscalDocumentLine::withoutEvents(fn () => FiscalDocumentLine::factory()->create([
        'fiscal_document_id' => $this->source->id, 'line_number' => 2, 'product_code' => $this->item->code,
        'unit_of_measure' => $this->item->unit_of_measure, 'quantity_units' => $units, 'quantity_scale' => $scale,
    ]));
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', [
        'documentPublicId' => $this->source->public_id, 'quantity_format' => 'decimal',
    ]))->assertUnprocessable()->assertJsonValidationErrors('repeat');
})->with(['sum' => [2, 0], 'rescaling' => [1, 4]]);

test('decimal repeat returns whole quantities as strings for the new client', function (): void {
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.repeat', [
        'documentPublicId' => $this->source->public_id, 'quantity_format' => 'decimal',
    ]))->assertOk()->assertJsonPath('data.lines.0.quantity', '2');
});
