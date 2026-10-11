<?php

use App\Actions\CompletePosSale;
use App\Actions\RecordStockMovement;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\FiscalSeriesStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\PosSale;
use App\Models\StockLevel;
use App\PaymentMethod;
use App\PosSessionStatus;
use App\StockMovementType;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

/**
 * 2 × 100,00 at 14 % IVA: 200,00 net, 28,00 tax, 228,00 to pay.
 */
const POS_TWO_ITEMS_GROSS = 22_800;

beforeEach(function () {
    Queue::fake();
});

// ------------------------------------------------------------------ a cash sale

test('a cash sale issues exactly one numbered Factura/Recibo and links it to the shift', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['code' => 'ART-1', 'name' => 'Cimento 50kg']);

    $response = $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item, '2']],
            POS_TWO_ITEMS_GROSS,
            ['tendered_minor' => 25_000],
        ))
        ->assertCreated()
        ->assertJsonPath('sale.total_minor', POS_TWO_ITEMS_GROSS)
        ->assertJsonPath('sale.tendered_minor', 25_000)
        ->assertJsonPath('sale.change_minor', 2_200)
        ->assertJsonPath('sale.payment_method', 'NU')
        ->assertJsonPath('sale.payment_method_label', 'Numerário')
        ->assertJsonPath('sale.customer_name', 'Consumidor final')
        ->assertJsonPath('sale.replayed', false)
        ->assertJsonPath('summary.sales_count', 1)
        ->assertJsonPath('summary.gross_total_minor', POS_TWO_ITEMS_GROSS)
        ->assertJsonPath('summary.cash_sales_minor', POS_TWO_ITEMS_GROSS)
        ->assertJsonPath('summary.expected_cash_minor', POS_TWO_ITEMS_GROSS)
        ->assertJsonPath('stock', []);

    $document = FiscalDocument::query()->with(['lines.taxes', 'submissions'])->sole();
    $sale = PosSale::query()->sole();

    expect($document->document_type)->toBe(FiscalDocumentType::InvoiceReceipt)
        ->and($document->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($document->document_no)->toBe('FR FR'.now('Africa/Luanda')->format('y').'POS/1')
        ->and($document->issue_sequence)->toBe(1)
        ->and($document->document_date->toDateString())->toBe(now('Africa/Luanda')->toDateString())
        ->and($document->payment_date->toDateString())->toBe(now('Africa/Luanda')->toDateString())
        ->and($document->due_date)->toBeNull()
        ->and($document->net_total_minor)->toBe(20_000)
        ->and($document->tax_payable_minor)->toBe(2_800)
        ->and($document->gross_total_minor)->toBe(POS_TWO_ITEMS_GROSS)
        ->and($document->payment_method)->toBe(PaymentMethod::Cash)
        ->and($document->customer_name)->toBe('Consumidor final')
        ->and($document->customer_tax_identification_number)->toBe('999999999')
        ->and($document->customer_country_code)->toBe('AO')
        ->and($document->customer_id)->toBeNull()
        ->and($document->establishment_id)->toBe($company['establishment']->id)
        ->and($document->issued_by_user_id)->toBe($company['owner']->id)
        ->and($document->lines)->toHaveCount(1)
        ->and($document->lines->first()->product_code)->toBe('ART-1')
        ->and($document->lines->first()->product_description)->toBe('Cimento 50kg')
        ->and($document->lines->first()->operation_type->value)->toBe('TB')
        ->and($document->submissions)->toHaveCount(1)
        ->and($document->printRecord()->exists())->toBeTrue()
        ->and($sale->fiscal_document_id)->toBe($document->id)
        ->and($sale->pos_session_id)->toBe($session->id)
        ->and($sale->total_minor)->toBe(POS_TWO_ITEMS_GROSS)
        ->and($sale->payment_method)->toBe(PaymentMethod::Cash)
        ->and($company['series']->fresh()->next_number)->toBe(2)
        ->and($company['series']->fresh()->status)->toBe(FiscalSeriesStatus::InUse);

    Queue::assertPushed(
        SubmitAgtDocument::class,
        fn (SubmitAgtDocument $job): bool => $job->submissionId === AgtSubmission::query()->sole()->id,
    );

    expect($response->json('sale.document_no'))->toBe($document->document_no)
        ->and($response->json('sale.document_public_id'))->toBe($document->public_id)
        ->and($response->json('sale.receipt_url'))->toBe(route('pos.sales.receipt', $sale, false));
});

test('services are sold as general services and products as goods', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $service = posItem($company, ['type' => 'service', 'code' => 'SERV-1', 'unit_price_minor' => 5_000]);
    $product = posItem($company, ['code' => 'ART-2', 'unit_price_minor' => 5_000]);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$service], [$product]], 11_400))
        ->assertCreated();

    $types = FiscalDocument::query()->sole()->lines->pluck('operation_type.value', 'product_code')->all();

    expect($types)->toBe(['SERV-1' => 'SG', 'ART-2' => 'TB']);
});

test('a sale at an exempt rate with a fractional quantity and a discount is calculated by the server', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, [
        'unit_price_minor' => 12_345,
        'tax_type' => 'IVA',
        'tax_code' => 'ISE',
        'tax_percentage' => '0.00',
        'tax_exemption_code' => 'M00',
    ]);

    // 123.45 × 2.5 × 90 % = 277.7625 → 277.76, no tax.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '2.5', '10']], 27_776))
        ->assertCreated();

    expect(FiscalDocument::query()->sole())
        ->net_total_minor->toBe(27_776)
        ->tax_payable_minor->toBe(0)
        ->gross_total_minor->toBe(27_776);
});

test('the till cannot send its own price, tax or description', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $payload = posSalePayload([[$item]], 100);
    $payload['lines'][0]['unit_price'] = '0.01';

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0');

    expect(FiscalDocument::query()->count())->toBe(0);
});

// ----------------------------------------------------------------------- stock

test('stock at the establishment drops for a tracked item and the response says what is left', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['tracks_stock' => true, 'code' => 'ART-T']);
    app(RecordStockMovement::class)->execute(
        item: $item,
        establishment: $company['establishment'],
        type: StockMovementType::Opening,
        quantityUnits: 10_000,
        unitCostMicros: 1_000_000,
    );

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '2.5']], 28_500))
        ->assertCreated()
        ->assertJsonPath('stock.0.catalogue_item_public_id', $item->public_id)
        ->assertJsonPath('stock.0.quantity_on_hand', '7.5');

    expect(StockLevel::query()->where('catalogue_item_id', $item->id)->sole()->quantity_units)->toBe(7_500);
});

// ------------------------------------------------------------------- idempotency

test('sending the same sale again returns the same sale and creates nothing new', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $payload = posSalePayload([[$item, '2']], POS_TWO_ITEMS_GROSS);

    $first = $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), $payload)
        ->assertCreated();
    $again = $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), $payload)
        ->assertOk()
        ->assertJsonPath('sale.replayed', true);

    expect($again->json('sale.public_id'))->toBe($first->json('sale.public_id'))
        ->and($again->json('sale.document_no'))->toBe($first->json('sale.document_no'))
        ->and(FiscalDocument::query()->count())->toBe(1)
        ->and(PosSale::query()->count())->toBe(1)
        ->and(AgtSubmission::query()->count())->toBe(1)
        ->and($company['series']->fresh()->next_number)->toBe(2);

    Queue::assertPushed(SubmitAgtDocument::class, 1);
});

test('a retry after the shift closed still answers with the sale it made', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $payload = posSalePayload([[$item]], 11_400);

    $this->actingAs($company['owner'])->postJson(route('pos.sales.store', $session), $payload)->assertCreated();
    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '114'])
        ->assertSessionHas('success');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), $payload)
        ->assertOk()
        ->assertJsonPath('sale.replayed', true);

    expect(FiscalDocument::query()->count())->toBe(1);
});

test('a client key already used in another shift is refused rather than reused', function () {
    $company = posCompany();
    $first = posOpenSession($company);
    $item = posItem($company);
    $payload = posSalePayload([[$item]], 11_400);

    $this->actingAs($company['owner'])->postJson(route('pos.sales.store', $first), $payload)->assertCreated();
    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $first), ['counted_cash' => '114'])
        ->assertSessionHas('success');
    $second = posOpenSession($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $second), $payload)
        ->assertStatus(409);

    expect(FiscalDocument::query()->count())->toBe(1);
});

// ----------------------------------------------------------------- the total

test('a total that differs from the one shown is refused and nothing is left behind', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '2']], POS_TWO_ITEMS_GROSS - 1))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_total_minor')
        ->assertJsonPath('errors.expected_total_minor.0', 'O total mudou desde que a venda foi apresentada. Reveja o carrinho.');

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and(PosSale::query()->count())->toBe(0)
        ->and(AgtSubmission::query()->count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);

    Queue::assertNothingPushed();
});

test('the catalogue price changing under the till is caught by the total', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $shown = 11_400;
    $item->update(['unit_price_minor' => 20_000]);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], $shown))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_total_minor');

    expect(FiscalDocument::query()->count())->toBe(0);
});

// -------------------------------------------------------------------- tendering

test('cash tendered below the total is refused and nothing is left behind', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['tendered_minor' => 11_399],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tendered_minor');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['tendered_minor' => null],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tendered_minor');

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);
});

test('cash far above the total is refused as a misread, not turned into change', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    // A barcode read into the amount field: 5 601 234 500 012,00 for an 114,00 sale.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['tendered_minor' => 560_123_450_001_200],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tendered_minor');

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);

    // The ceiling itself is still allowed: 100 000,00 of change.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['tendered_minor' => 11_400 + CompletePosSale::MAX_CHANGE_MINOR],
        ))
        ->assertCreated()
        ->assertJsonPath('sale.change_minor', CompletePosSale::MAX_CHANGE_MINOR);
});

test('exact cash gives no change', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertCreated()
        ->assertJsonPath('sale.change_minor', 0);
});

test('any other method settles exactly the total and ignores a tendered figure', function (string $method) {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['payment_method' => $method, 'tendered_minor' => 999_999],
        ))
        ->assertCreated()
        ->assertJsonPath('sale.tendered_minor', 11_400)
        ->assertJsonPath('sale.change_minor', 0)
        ->assertJsonPath('sale.payment_method', $method)
        ->assertJsonPath('summary.cash_sales_minor', 0);

    expect(FiscalDocument::query()->sole()->payment_method->value)->toBe($method);
})->with(['CD', 'CC', 'TB', 'OU']);

test('only the methods a till takes are accepted', function (string $method) {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400, ['payment_method' => $method]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');
})->with(['CH', 'LC', 'DE', 'XX']);

// ------------------------------------------------------------------ the lines

test('an unknown, inactive or other-company article is refused with the line it is on', function () {
    $company = posCompany();
    $other = posCompany();
    $session = posOpenSession($company);
    $good = posItem($company);
    $inactive = posItem($company, ['is_active' => false]);
    $foreign = posItem($other);
    $dollars = posItem($company, ['currency_code' => 'USD']);

    foreach ([$inactive, $foreign, $dollars] as $bad) {
        $this->actingAs($company['owner'])
            ->postJson(route('pos.sales.store', $session), posSalePayload([[$good], [$bad]], 22_800))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.1.catalogue_item_public_id');
    }

    $unknown = posSalePayload([[$good]], 11_400);
    $unknown['lines'][0]['catalogue_item_public_id'] = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), $unknown)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.catalogue_item_public_id');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a sale needs lines, sane quantities and a well-formed key', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([], 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '0']], 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.quantity');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '1.00001']], 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.quantity');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '1', '100.01']], 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.discount_percentage');

    foreach (['short', str_repeat('a', 65), 'has spaces in it ok!'] as $key) {
        $this->actingAs($company['owner'])
            ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400, ['client_key' => $key]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_key');
    }

    $many = array_fill(0, 201, [$item]);
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload($many, 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');
});

test('a hundred percent discount cannot produce a sale of nothing', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '1', '100']], 1))
        ->assertUnprocessable();

    expect(FiscalDocument::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ customers

test('a saved customer is recorded on the document and pays the agreed price', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10_000]);
    $customer = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cliente Fiel, Lda.',
        'tax_identification_number' => '5411111111',
    ]);
    CustomerPrice::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'customer_id' => $customer->id,
        'catalogue_item_id' => $item->id,
        'unit_price_minor' => 8_000,
    ]);

    // 80,00 + 14 % = 91,20, not the 114,00 of the catalogue.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            9_120,
            ['customer_public_id' => $customer->public_id],
        ))
        ->assertCreated()
        ->assertJsonPath('sale.customer_name', 'Cliente Fiel, Lda.');

    $document = FiscalDocument::query()->sole();

    expect($document->customer_id)->toBe($customer->id)
        ->and($document->customer_tax_identification_number)->toBe('5411111111')
        ->and($document->gross_total_minor)->toBe(9_120);
});

test('without an agreed price a saved customer pays the catalogue price', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10_000]);
    $customer = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
    ]);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['customer_public_id' => $customer->public_id],
        ))
        ->assertCreated();
});

test('a typed name and tax number are recorded on the document', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['customer' => ['name' => 'Maria Fernanda', 'tax_identification_number' => '005678901la042']],
        ))
        ->assertCreated()
        ->assertJsonPath('sale.customer_name', 'Maria Fernanda');

    $document = FiscalDocument::query()->sole();

    expect($document->customer_tax_identification_number)->toBe('005678901LA042')
        ->and($document->customer_country_code)->toBe('AO')
        ->and($document->customer_id)->toBeNull();
});

test('a customer that is inactive or belongs to another company is refused', function () {
    $company = posCompany();
    $other = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $foreign = Customer::factory()->create([
        'workspace_id' => $other['legalEntity']->workspace_id,
        'legal_entity_id' => $other['legalEntity']->id,
    ]);
    $inactive = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'is_active' => false,
    ]);

    foreach ([$foreign, $inactive] as $customer) {
        $this->actingAs($company['owner'])
            ->postJson(route('pos.sales.store', $session), posSalePayload(
                [[$item]],
                11_400,
                ['customer_public_id' => $customer->public_id],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_public_id');
    }

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a malformed tax number on a typed customer is refused', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item]],
            11_400,
            ['customer' => ['name' => 'Maria', 'tax_identification_number' => '12']],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer.tax_identification_number');
});

// ----------------------------------------------------------------- the series

test('with no Factura/Recibo series the sale is refused and nothing is left behind', function (string $why) {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $series = $company['series'];

    match ($why) {
        'none' => $series->delete(),
        'wrong type' => $series->forceFill(['document_type' => FiscalDocumentType::Invoice])->save(),
        'last year' => $series->forceFill(['series_year' => $series->series_year - 1])->save(),
        'closed' => $series->forceFill(['status' => FiscalSeriesStatus::Closed])->save(),
        'exhausted' => $series->forceFill(['next_number' => 501])->save(),
        'other establishment' => $series->forceFill([
            'establishment_id' => Establishment::factory()->create([
                'workspace_id' => $company['legalEntity']->workspace_id,
                'legal_entity_id' => $company['legalEntity']->id,
            ])->id,
        ])->save(),
        'connection not verified' => $company['connection']->forceFill(['status' => 'ready'])->save(),
    };

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Não há série de Factura/Recibo disponível neste estabelecimento. Peça uma série à AGT antes de vender.',
        ]);

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and(PosSale::query()->count())->toBe(0);

    Queue::assertNothingPushed();
})->with(['none', 'wrong type', 'last year', 'closed', 'exhausted', 'other establishment', 'connection not verified']);

test('an issuing refusal comes back as a readable 409 and rolls the sale back', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $company['connection']->forceFill(['taxpayer_key_fingerprint' => str_repeat('0', 64)])->save();

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Uma chave fiscal mudou desde a última verificação. Teste novamente a ligação à AGT.');

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);
});

// ---------------------------------------------------------------- who may sell

test('a closed shift answers 409 and sells nothing', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '0'])
        ->assertSessionHas('success');

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Este turno já foi fechado.']);

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('only the person who opened the shift sells on it', function () {
    $company = posCompany();
    $cashier = posMember($company, WorkspaceRole::Billing);
    $session = posOpenSession($company, $cashier);
    $item = posItem($company);

    // Not even the owner.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertCreated();
});

test('a shift of another company is not found', function () {
    $company = posCompany();
    $other = posCompany();
    $theirs = posOpenSession($other);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $theirs), posSalePayload([[$item]], 11_400))
        ->assertNotFound();

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a viewer cannot sell even on a shift that is theirs', function () {
    $company = posCompany();
    $viewer = posMember($company, WorkspaceRole::Viewer);
    $session = posOpenSession($company, $viewer);
    $item = posItem($company);

    $this->actingAs($viewer)
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertForbidden()
        ->assertJsonPath('message', 'Não tem permissão para vender.');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('every role that may issue invoices may sell', function (WorkspaceRole $role) {
    $company = posCompany();
    $member = posMember($company, $role);
    $session = posOpenSession($company, $member);
    $item = posItem($company);

    $this->actingAs($member)
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertCreated();
})->with([WorkspaceRole::Administrator, WorkspaceRole::Accountant, WorkspaceRole::Billing]);

test('selling needs multi-factor authentication but not the password again', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    // No password confirmation in the session: still fine.
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertCreated();

    $company['owner']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->assertRedirect(route('settings.security'));

    expect(FiscalDocument::query()->count())->toBe(1);
});

test('a validation failure answers JSON even when the till did not ask for it', function () {
    $company = posCompany();
    $session = posOpenSession($company);

    $this->actingAs($company['owner'])
        ->post(route('pos.sales.store', $session), [])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['client_key', 'lines']]);
});

// ------------------------------------------------------------- the summary

test('the shift summary adds sales by method, cash in and cash out', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 10_000);
    $item = posItem($company);

    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item, '2']], POS_TWO_ITEMS_GROSS))
        ->assertCreated();
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400, ['payment_method' => 'CD']))
        ->assertCreated();
    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), ['type' => 'in', 'amount' => '50', 'reason' => 'Reforço'])
        ->assertSessionHas('success');
    $this->actingAs($company['owner'])
        ->post(route('pos.cash-movements.store', $session), ['type' => 'out', 'amount' => '30', 'reason' => 'Retirada'])
        ->assertSessionHas('success');

    $this->actingAs($company['owner'])
        ->get(route('pos.sessions.show', $session))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.sales_count', 2)
            ->where('summary.gross_total_minor', 34_200)
            ->where('summary.net_total_minor', 30_000)
            ->where('summary.tax_total_minor', 4_200)
            ->where('summary.by_method', [
                ['method' => 'NU', 'label' => 'Numerário', 'count' => 1, 'total_minor' => 22_800],
                ['method' => 'CD', 'label' => 'Multicaixa / cartão de débito', 'count' => 1, 'total_minor' => 11_400],
            ])
            ->where('summary.cash_sales_minor', 22_800)
            ->where('summary.cash_in_minor', 5_000)
            ->where('summary.cash_out_minor', 3_000)
            // 100,00 float + 228,00 cash sales + 50,00 in − 30,00 out.
            ->where('summary.expected_cash_minor', 34_800)
            ->where('summary.first_document_no', 'FR FR'.now('Africa/Luanda')->format('y').'POS/1')
            ->where('summary.last_document_no', 'FR FR'.now('Africa/Luanda')->format('y').'POS/2')
            ->has('sales', 2)
            ->where('sales.0.document_no', 'FR FR'.now('Africa/Luanda')->format('y').'POS/2')
            ->where('sales.0.payment_method', 'CD'));

    $this->actingAs($company['owner'])
        ->post(route('pos.sessions.close', $session), ['counted_cash' => '350'])
        ->assertSessionHas('success');

    expect($session->fresh())
        ->status->toBe(PosSessionStatus::Closed)
        ->expected_cash_minor->toBe(34_800)
        ->cash_difference_minor->toBe(200)
        ->and($session->fresh()->closing_summary['by_method'])->toHaveCount(2);
});

// ----------------------------------------------------------------- the till page

test('the till page carries what a shift needs', function () {
    $company = posCompany();
    $session = posOpenSession($company, float: 2_000);
    $tracked = posItem($company, ['tracks_stock' => true, 'code' => 'ART-T', 'barcode' => '5601234567890', 'name' => 'Água']);
    posItem($company, ['is_active' => false, 'name' => 'Descontinuado']);
    posItem($company, ['currency_code' => 'USD', 'name' => 'Em dólares']);
    app(RecordStockMovement::class)->execute(
        item: $tracked,
        establishment: $company['establishment'],
        type: StockMovementType::Opening,
        quantityUnits: 12_500,
        unitCostMicros: 1_000_000,
    );
    $customer = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
    ]);
    CustomerPrice::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'customer_id' => $customer->id,
        'catalogue_item_id' => $tracked->id,
        'unit_price_minor' => 8_050,
    ]);
    $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$tracked]], 11_400))
        ->assertCreated();

    $this->actingAs($company['owner'])
        ->get(route('pos.show'))
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index', false)
            ->where('company.legal_name', 'VAP Loja, Lda.')
            ->where('company.trade_name', 'Loja VAP')
            ->where('company.currency_code', 'AOA')
            ->where('canManageRegisters', true)
            ->where('canSell', true)
            ->where('paymentMethods', [
                ['value' => 'NU', 'label' => 'Numerário'],
                ['value' => 'CD', 'label' => 'Multicaixa / cartão de débito'],
                ['value' => 'CC', 'label' => 'Cartão de crédito'],
                ['value' => 'TB', 'label' => 'Transferência bancária'],
                ['value' => 'OU', 'label' => 'Outro meio'],
            ])
            ->where('finalConsumer', [
                'name' => 'Consumidor final',
                'tax_identification_number' => '999999999',
                'country_code' => 'AO',
            ])
            ->has('registers', 1)
            ->where('registers.0.name', 'Caixa 1')
            ->where('registers.0.has_series', true)
            ->where('registers.0.establishment.name', 'Loja central')
            ->where('registers.0.open_session.is_mine', true)
            ->where('session.public_id', $session->public_id)
            ->where('session.opening_float_minor', 2_000)
            ->where('session.currency_code', 'AOA')
            ->where('session.register.name', 'Caixa 1')
            ->where('session.series_available', true)
            ->where('session.summary.sales_count', 1)
            ->where('session.summary.expected_cash_minor', 13_400)
            ->has('session.recent_sales', 1)
            ->where('session.recent_sales.0.total_minor', 11_400)
            ->where('session.cash_movements', [])
            ->has('catalogue', 1)
            ->where('catalogue.0.code', 'ART-T')
            ->where('catalogue.0.barcode', '5601234567890')
            ->where('catalogue.0.unit_price_minor', 10_000)
            ->where('catalogue.0.tax_percentage', '14.00')
            ->where('catalogue.0.tracks_stock', true)
            ->where('catalogue.0.quantity_on_hand', '11.5')
            ->has('customers', 1)
            ->where('customers.0.public_id', $customer->public_id)
            ->where("agreedPrices.{$customer->public_id}.{$tracked->public_id}", '80.50'));
});

test('without an open shift the page sends the registers and nothing else', function () {
    $company = posCompany();
    posItem($company);
    Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
    ]);
    $cashier = posMember($company, WorkspaceRole::Billing);
    posOpenSession($company, $cashier);

    $this->actingAs($company['owner'])
        ->get(route('pos.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session', null)
            ->where('catalogue', [])
            ->where('customers', [])
            ->where('registers.0.open_session.is_mine', false)
            ->where('registers.0.open_session.opened_by_name', $cashier->name));
});

test('a register without a series says so', function () {
    $company = posCompany();
    $company['series']->delete();

    $this->actingAs($company['owner'])
        ->get(route('pos.show'))
        ->assertInertia(fn (Assert $page) => $page->where('registers.0.has_series', false));
});

test('a viewer sees the page but cannot sell or manage registers', function () {
    $company = posCompany();
    $viewer = posMember($company, WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('pos.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSell', false)
            ->where('canManageRegisters', false));
});

test('the till page of one company never shows another company', function () {
    $company = posCompany();
    $other = posCompany();
    posOpenSession($other);

    $this->actingAs($company['owner'])
        ->get(route('pos.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('registers', 1)
            ->where('registers.0.open_session', null)
            ->where('session', null));
});

// -------------------------------------------------------------------- receipt

test('the receipt is the full print document plus the cash handed over', function () {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $cashier = $company['owner'];

    $url = $this->actingAs($cashier)
        ->postJson(route('pos.sales.store', $session), posSalePayload(
            [[$item, '2']],
            POS_TWO_ITEMS_GROSS,
            ['tendered_minor' => 30_000],
        ))
        ->assertCreated()
        ->json('sale.receipt_url');

    $this->actingAs($cashier)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Receipt', false)
            ->where('document.document_type', 'FR')
            ->where('document.totals.gross_minor', POS_TWO_ITEMS_GROSS)
            ->where('document.payment_method_label', 'Numerário')
            ->has('document.lines', 1)
            ->where('sale.tendered_minor', 30_000)
            ->where('sale.change_minor', 7_200)
            ->where('sale.payment_method_label', 'Numerário')
            ->where('sale.register_name', 'Caixa 1')
            ->where('sale.cashier_name', $cashier->name));
});

test('a receipt is for members of the company only', function () {
    $company = posCompany();
    $other = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);

    $url = $this->actingAs($company['owner'])
        ->postJson(route('pos.sales.store', $session), posSalePayload([[$item]], 11_400))
        ->json('sale.receipt_url');

    $this->actingAs($other['owner'])->get($url)->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});
