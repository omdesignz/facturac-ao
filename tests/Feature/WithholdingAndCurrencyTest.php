<?php

use App\Actions\SaveFiscalDocumentDraft;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\V1_2\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\WithholdingType;
use Carbon\CarbonImmutable;

/**
 * Adds withholding to an already-issued document.
 *
 * The rows are written straight rather than through the draft action, because
 * what is under test here is how a stored withholding reaches the paper, the
 * payload and the audit file — not how it got stored.
 *
 * @param  array<string, mixed>  $fixture
 */
function withhold(
    FiscalDocument $document,
    WithholdingType $type,
    int $rateBasisPoints,
): FiscalDocument {
    $base = $type->isLeviedOnVat()
        ? $document->tax_payable_minor
        : $document->net_total_minor;

    $document->withholdings()->create([
        'workspace_id' => $document->workspace_id,
        'legal_entity_id' => $document->legal_entity_id,
        'withholding_type' => $type,
        'base_minor' => $base,
        'rate_basis_points' => $rateBasisPoints,
        'amount_minor' => app(FiscalCalculator::class)->withheldAmount($base, $rateBasisPoints),
    ]);

    return $document->fresh();
}

// ------------------------------------------------------------- the arithmetic

test('retention is charged on the supply and captive VAT on the tax', function () {
    $calculator = app(FiscalCalculator::class);

    // 750 000,00 of services at 14%: 105 000,00 of VAT.
    $net = 75_000_000;
    $tax = 10_500_000;

    // 6,5% retention on the supply.
    expect($calculator->withheldAmount($net, 650))->toBe(4_875_000)
        // Half the VAT captived, which is a slice of the tax and not of the sale.
        ->and($calculator->withheldAmount($tax, 5_000))->toBe(5_250_000);
});

test('the withheld amount rounds half up on the cêntimo', function () {
    $calculator = app(FiscalCalculator::class);

    // 100,05 at 50% is 50,025 — which has to land on 50,03, not 50,02.
    expect($calculator->withheldAmount(10_005, 5_000))->toBe(5_003);
});

test('a rate above one hundred percent is refused', function () {
    app(FiscalCalculator::class)->withheldAmount(1_000, 10_001);
})->throws(InvalidArgumentException::class, 'cannot exceed 100%');

test('a document in kwanzas converts through exactly one', function () {
    $calculator = app(FiscalCalculator::class);

    expect($calculator->convertedAmount(123_456_789, 1_000_000))->toBe(123_456_789);
});

test('a foreign amount converts at the stated rate', function () {
    $calculator = app(FiscalCalculator::class);

    // 1 000,00 USD at 912,50 kwanzas is 912 500,00.
    expect($calculator->convertedAmount(100_000, 912_500_000))->toBe(91_250_000);
});

test('an exchange rate of zero is refused', function () {
    app(FiscalCalculator::class)->convertedAmount(1_000, 0);
})->throws(InvalidArgumentException::class, 'greater than zero');

// ------------------------------------------------------------- what is stored

test('the base is derived from the document rather than taken from the form', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $document = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer, withholdings: [
            ['type' => 'II', 'rate_basis_points' => 650],
            ['type' => 'IVA-CATIVO', 'rate_basis_points' => 5_000],
        ]),
    );

    $retention = $document->withholdings->firstWhere('withholding_type', WithholdingType::IndustrialTax);
    $captive = $document->withholdings->firstWhere('withholding_type', WithholdingType::CaptiveVat);

    expect($retention->base_minor)->toBe($document->net_total_minor)
        ->and($captive->base_minor)->toBe($document->tax_payable_minor)
        // The two bases differ, so the same rate would give different figures.
        ->and($retention->base_minor)->not->toBe($captive->base_minor);
});

test('withholding leaves the document total alone', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $without = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer),
    );

    $with = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer, withholdings: [
            ['type' => 'IRT', 'rate_basis_points' => 650],
        ]),
    );

    /*
     * The AGT sheet keeps the retained figure apart from the totals, and it has
     * to stay that way: what the buyer owes on the document is unchanged, they
     * simply pay part of it to someone else.
     */
    expect($with->gross_total_minor)->toBe($without->gross_total_minor)
        ->and($with->withholdings)->toHaveCount(1);
});

test('saving again replaces the withholding rather than adding to it', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $draft = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer, withholdings: [
            ['type' => 'IRT', 'rate_basis_points' => 650],
        ]),
    );

    $saved = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer, withholdings: [
            ['type' => 'IRT', 'rate_basis_points' => 1_050],
        ]),
        $draft,
    );

    expect($saved->withholdings)->toHaveCount(1)
        ->and($saved->withholdings->first()->rate_basis_points)->toBe(1_050);
});

test('a document with no withholding stores none', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $document = app(SaveFiscalDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        documentProfile($fixture, $customer),
    );

    expect($document->withholdings)->toHaveCount(0)
        ->and($document->currency_code)->toBe('AOA')
        ->and($document->exchange_rate_micro)->toBe(1_000_000);
});

// ------------------------------------------------------------------ the paper

test('the printed document shows what the buyer keeps back', function () {
    $fixture = pdfFixture();
    $document = withhold(documentWithLines($fixture), WithholdingType::IndustrialTax, 650);

    $payload = app(FiscalDocumentPresenter::class)->forPrint($document);

    expect($payload['withholdings'])->toHaveCount(1)
        ->and($payload['withholdings'][0]['tax'])->toBe('Imposto Industrial')
        ->and($payload['withholdings'][0]['rate'])->toBe('6.50')
        ->and($payload['withholdings'][0]['base_minor'])->toBe($document->net_total_minor)
        ->and($payload['withholding_total_minor'])->toBe($payload['withholdings'][0]['amount_minor']);
});

test('a document with nothing withheld carries no withholding block', function () {
    $fixture = pdfFixture();
    $payload = app(FiscalDocumentPresenter::class)->forPrint(documentWithLines($fixture));

    expect($payload['withholdings'])->toBe([])
        ->and($payload['withholding_total_minor'])->toBe(0)
        ->and($payload['is_foreign_currency'])->toBeFalse();
});

test('the receipt states the real rate rather than a standing one', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, type: FiscalDocumentType::Receipt);
    $document->forceFill([
        'currency_code' => 'USD',
        'exchange_rate_micro' => 912_500_000,
    ])->saveQuietly();

    $payload = app(FiscalDocumentPresenter::class)->forPrint($document->fresh());

    expect($payload['exchange_rate'])->toBe('912,5000')
        ->and($payload['currency_code'])->toBe('USD')
        ->and($payload['is_foreign_currency'])->toBeTrue()
        ->and($payload['totals']['gross_base_minor'])
        ->toBe(app(FiscalCalculator::class)->convertedAmount($document->gross_total_minor, 912_500_000));
});

test('a kwanza receipt still states its rate, as one', function () {
    $fixture = pdfFixture();
    $payload = app(FiscalDocumentPresenter::class)->forPrint(
        documentWithLines($fixture, type: FiscalDocumentType::Receipt),
    );

    // Stated rather than blank: a fiscal document with an empty rate reads as
    // missing data rather than as the kwanza it is.
    expect($payload['exchange_rate'])->toBe('1,0000')
        ->and($payload['is_foreign_currency'])->toBeFalse();
});

test('the PDF renders with both a withholding and a foreign currency', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $document->forceFill([
        'currency_code' => 'EUR',
        'exchange_rate_micro' => 1_050_750_000,
    ])->saveQuietly();

    $pdf = app(FiscalDocumentPdf::class)->render(
        withhold($document->fresh(), WithholdingType::CaptiveVat, 5_000),
    );

    expect($pdf)->toStartWith('%PDF-')
        ->and(pageCount($pdf))->toBeGreaterThan(0);
});

// ------------------------------------------------------------------- the AGT

/** The shared fixture stops short of the entry stamp the payload insists on. */
function readyForAgt(FiscalDocument $document): FiscalDocument
{
    $document->forceFill(['system_entry_at' => now()])->saveQuietly();

    return $document->fresh();
}

test('the AGT payload is given kwanzas and told the original currency', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $document->forceFill([
        'currency_code' => 'USD',
        'exchange_rate_micro' => 912_500_000,
    ])->saveQuietly();

    $payload = app(FiscalDocumentPayloadBuilder::class)->document(readyForAgt($document));

    expect((string) $payload['documentTotals']['grossTotal'])
        ->toBe((string) CanonicalNumber::fromMinorUnits(
            app(FiscalCalculator::class)->convertedAmount(
                $document->gross_total_minor,
                912_500_000,
            ),
        ))
        ->and($payload['currency']['currencyCode'])->toBe('USD');
});

test('a kwanza document is sent exactly as it was before', function () {
    $fixture = pdfFixture();
    $document = readyForAgt(documentWithLines($fixture));

    $payload = app(FiscalDocumentPayloadBuilder::class)->document($document);

    /*
     * The conversion is the identity at a rate of one, and the currency block
     * is left off entirely. Every document issued so far must produce the same
     * bytes it did before foreign currency existed, or its signature moves.
     */
    expect((string) $payload['documentTotals']['grossTotal'])
        ->toBe((string) CanonicalNumber::fromMinorUnits($document->gross_total_minor))
        ->and($payload)->not->toHaveKey('currency')
        ->and($payload)->not->toHaveKey('withholdingTax');
});

test('the payload carries what the buyer withholds', function () {
    $fixture = pdfFixture();
    $document = withhold(readyForAgt(documentWithLines($fixture)), WithholdingType::IncomeTax, 650);

    $payload = app(FiscalDocumentPayloadBuilder::class)->document($document);

    expect($payload['withholdingTax'])->toHaveCount(1)
        ->and($payload['withholdingTax'][0]['withholdingTaxType'])->toBe('IRT');
});

// ---------------------------------------------------------------- the SAF-T

test('the audit file states the currency and rate of a foreign document', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $document->forceFill([
        'currency_code' => 'USD',
        'exchange_rate_micro' => 912_500_000,
        'document_date' => '2026-06-01',
    ])->saveQuietly();

    ['root' => $root] = saftFor($fixture);

    $currency = $root->xpath('//s:Invoice/s:DocumentTotals/s:Currency')[0];

    expect((string) $currency->CurrencyCode)->toBe('USD')
        ->and((string) $currency->ExchangeRate)->toBe('912.500000')
        // The original amount, kept beside the converted one.
        ->and((string) $currency->CurrencyAmount)
        ->toBe(number_format($document->gross_total_minor / 100, 2, '.', ''));
});

test('the audit file totals are in kwanzas, not in the document currency', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);
    $document->forceFill([
        'currency_code' => 'USD',
        'exchange_rate_micro' => 912_500_000,
        'document_date' => '2026-06-01',
    ])->saveQuietly();

    ['root' => $root] = saftFor($fixture);

    $totals = $root->xpath('//s:Invoice/s:DocumentTotals')[0];
    $expected = app(FiscalCalculator::class)->convertedAmount(
        $document->gross_total_minor,
        912_500_000,
    );

    expect((string) $totals->GrossTotal)
        ->toBe(number_format($expected / 100, 2, '.', ''))
        // The period total sums documents, so it has to convert as well.
        ->and((string) $root->xpath('//s:SalesInvoices/s:TotalDebit')[0])
        ->toBe(number_format(
            app(FiscalCalculator::class)->convertedAmount(
                $document->net_total_minor,
                912_500_000,
            ) / 100,
            2,
            '.',
            '',
        ));
});

test('a kwanza document carries no currency block at all', function () {
    $fixture = pdfFixture();
    documentWithLines($fixture);

    ['root' => $root] = saftFor($fixture);

    expect($root->xpath('//s:Invoice/s:DocumentTotals/s:Currency'))->toBe([]);
});

test('the audit file reports what was withheld', function () {
    $fixture = pdfFixture();
    $document = withhold(documentWithLines($fixture), WithholdingType::CaptiveVat, 5_000);

    ['root' => $root] = saftFor($fixture);

    $withheld = $root->xpath('//s:Invoice/s:WithholdingTax')[0];

    expect((string) $withheld->WithholdingTaxType)->toBe('IVA-CATIVO')
        ->and((string) $withheld->WithholdingTaxAmount)
        ->toBe(number_format($document->withholdings->first()->amount_minor / 100, 2, '.', ''));
});

// -------------------------------------------------------------- the requests

test('a foreign currency without a rate is refused', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->post(route('invoices.store'), [
            ...documentRequest($fixture, $customer),
            'currency_code' => 'USD',
        ])
        ->assertSessionHasErrors('exchange_rate');
});

test('a currency the company cannot invoice in is refused', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->post(route('invoices.store'), [
            ...documentRequest($fixture, $customer),
            'currency_code' => 'XYZ',
            'exchange_rate' => '2',
        ])
        ->assertSessionHasErrors('currency_code');
});

test('a stray rate on a kwanza document is ignored, not stored', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    // What happens when someone picks USD, types a rate, then goes back to AOA.
    $this->actingAs($fixture['owner'])
        ->post(route('invoices.store'), [
            ...documentRequest($fixture, $customer),
            'exchange_rate' => '912.5',
        ])
        ->assertRedirect();

    expect(FiscalDocument::query()->latest('id')->firstOrFail()->exchange_rate_micro)
        ->toBe(1_000_000);
});

test('the same tax cannot be withheld twice on one document', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->post(route('invoices.store'), [
            ...documentRequest($fixture, $customer),
            'withholdings' => [
                ['type' => 'IRT', 'rate_percentage' => '6.5'],
                ['type' => 'IRT', 'rate_percentage' => '10.5'],
            ],
        ])
        ->assertSessionHasErrors('withholdings.1.type');
});

test('a rate is turned into basis points on the way in', function () {
    $fixture = pdfFixture();
    $customer = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->post(route('invoices.store'), [
            ...documentRequest($fixture, $customer),
            'currency_code' => 'USD',
            'exchange_rate' => '912.5',
            'withholdings' => [['type' => 'II', 'rate_percentage' => '6.5']],
        ])
        ->assertRedirect();

    $document = FiscalDocument::query()->latest('id')->firstOrFail();

    expect($document->withholdings->first()->rate_basis_points)->toBe(650)
        ->and($document->exchange_rate_micro)->toBe(912_500_000)
        ->and($document->currency_code)->toBe('USD');
});

// ------------------------------------------------------------ the customer

test('a buyer that always withholds is remembered', function () {
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('customers.store'), [
            'name' => 'Ministério das Finanças',
            'tax_identification_number' => '5417000000',
            'country_code' => 'AO',
            'is_active' => true,
            'payment_terms_days' => 30,
            'auto_send_documents' => false,
            'withholding_type' => 'IVA-CATIVO',
            'withholding_rate' => '50',
        ])
        ->assertRedirect();

    $customer = Customer::query()->where('name', 'Ministério das Finanças')->firstOrFail();

    expect($customer->withholding_type)->toBe(WithholdingType::CaptiveVat)
        ->and($customer->withholding_rate_basis_points)->toBe(5_000);
});

test('a rate without a tax to attach it to is refused', function () {
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('customers.store'), [
            'name' => 'Cliente sem imposto',
            'tax_identification_number' => '5417000001',
            'country_code' => 'AO',
            'is_active' => true,
            'payment_terms_days' => 0,
            'auto_send_documents' => false,
            'withholding_type' => 'IRT',
        ])
        ->assertSessionHasErrors('withholding_rate');
});

test('the invoice form is offered the buyer default and the currencies', function () {
    $fixture = pdfFixture();

    Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'withholding_type' => WithholdingType::CaptiveVat,
        'withholding_rate_basis_points' => 5_000,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customers.0.withholding.type', 'IVA-CATIVO')
            ->where('customers.0.withholding.rate_percentage', '50.00')
            ->where('currencies.0', 'AOA')
            ->where('withholdingTypes.0.value', 'IRT')
            ->etc()
        );
});

/**
 * The smallest document the request will accept, as form input.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function documentRequest(array $fixture, Customer $customer): array
{
    return [
        'document_type' => FiscalDocumentType::Invoice->value,
        'document_date' => CarbonImmutable::now('Africa/Luanda')->toDateString(),
        'due_date' => null,
        'currency_code' => 'AOA',
        'establishment_public_id' => $fixture['establishment']->public_id,
        'customer_public_id' => $customer->public_id,
        'customer' => [
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'country_code' => 'AO',
            'address_line' => null,
        ],
        'notes' => null,
        'lines' => [[
            'operation_type' => 'SG',
            'product_code' => 'SRV-1',
            'product_description' => 'Prestação de serviços',
            'quantity' => '1',
            'unit_of_measure' => 'UN',
            'unit_price' => '750000.00',
            'discount_percentage' => '0',
            'tax' => [
                'type' => 'IVA',
                'code' => 'NOR',
                'percentage' => '14',
                'exemption_code' => null,
            ],
        ]],
    ];
}

/**
 * The same document as the profile the draft action takes.
 *
 * @param  array<string, mixed>  $fixture
 * @param  list<array{type: string, rate_basis_points: int}>  $withholdings
 * @return array<string, mixed>
 */
function documentProfile(array $fixture, Customer $customer, array $withholdings = []): array
{
    $request = documentRequest($fixture, $customer);
    $line = $request['lines'][0];

    return [
        ...$request,
        'exchange_rate_micro' => 1_000_000,
        'withholdings' => $withholdings,
        'references_document_public_id' => null,
        'adjustment_reason' => null,
        'settlements' => [],
        'lines' => [[
            'operation_type' => $line['operation_type'],
            'product_code' => $line['product_code'],
            'product_description' => $line['product_description'],
            'quantity' => $line['quantity'],
            'unit_of_measure' => $line['unit_of_measure'],
            'unit_price' => $line['unit_price'],
            'discount_percentage' => $line['discount_percentage'],
            'tax_type' => $line['tax']['type'],
            'tax_code' => $line['tax']['code'],
            'tax_percentage' => $line['tax']['percentage'],
            'tax_exemption_code' => $line['tax']['exemption_code'],
        ]],
    ];
}
