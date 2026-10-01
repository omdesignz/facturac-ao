<?php

use App\Actions\ConvertQuoteToInvoice;
use App\Actions\GenerateRecurringInvoices;
use App\Actions\SendFiscalDocumentToCustomer;
use App\Analytics\AgingQuery;
use App\Exceptions\BillingActionRefused;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Notifications\FiscalDocumentIssued;
use App\QuoteStatus;
use App\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function billingFixture(int $termsDays = 30): array
{
    $owner = User::factory()->withWorkspace('VAP Facturação')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5000000321',
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Kwanza Mercantil, Lda.',
        'email' => 'financeiro@kwanza.ao',
        'payment_terms_days' => $termsDays,
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'customer');
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function quotePayload(array $fixture, string $price = '100000.00'): array
{
    return [
        'establishment_public_id' => $fixture['establishment']->public_id,
        'customer_public_id' => $fixture['customer']->public_id,
        'customer' => [
            'name' => $fixture['customer']->name,
            'tax_identification_number' => $fixture['customer']->tax_identification_number,
            'country_code' => 'AO',
            'address_line' => 'Luanda',
        ],
        'issue_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'notes' => null,
        'lines' => [[
            'product_code' => 'SRV-1',
            'product_description' => 'Consultoria',
            'unit_of_measure' => 'UN',
            'quantity' => '1',
            'unit_price' => $price,
            'discount_rate' => '0',
            'tax_type' => 'IVA',
            'tax_code' => 'NOR',
            'tax_percentage' => '14.00',
            'tax_exemption_code' => null,
        ]],
    ];
}

// ------------------------------------------------------------- payment terms

test('a customer carries the terms that were agreed', function () {
    $fixture = billingFixture(termsDays: 45);

    expect($fixture['customer']->payment_terms_days)->toBe(45);
});

test('terms of zero days fall due on the day of issue, not never', function () {
    $fixture = billingFixture(termsDays: 0);
    $issuedOn = CarbonImmutable::parse('2026-03-10');

    // A blank due date would make a cash sale permanently un-overdue.
    expect($fixture['customer']->dueDateFor($issuedOn)->toDateString())
        ->toBe('2026-03-10');
});

test('the due date derives from the terms', function () {
    $fixture = billingFixture(termsDays: 30);
    $issuedOn = CarbonImmutable::parse('2026-03-10');

    expect($fixture['customer']->dueDateFor($issuedOn)->toDateString())
        ->toBe('2026-04-09');
});

// --------------------------------------------------------- per-customer price

test('an agreed price is offered instead of the list price', function () {
    $fixture = billingFixture();

    $item = CatalogueItem::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'unit_price_minor' => 1_000_000,
    ]);

    CustomerPrice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'customer_id' => $fixture['customer']->id,
        'catalogue_item_id' => $item->id,
        'unit_price_minor' => 850_000,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($item) {
            $customer = collect($page->toArray()['props']['customers'])->first();

            expect($customer['agreed_prices'][$item->public_id])->toBe('8500.00');
        });
});

test('agreeing a price twice replaces it rather than stacking', function () {
    $fixture = billingFixture();
    $item = CatalogueItem::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ]);

    foreach (['900.00', '820.00'] as $price) {
        $this->actingAs($fixture['owner'])
            ->post(route('customers.prices.store', $fixture['customer']), [
                'catalogue_item' => $item->public_id,
                'unit_price' => $price,
            ])->assertRedirect();
    }

    $prices = CustomerPrice::query()->where('customer_id', $fixture['customer']->id)->get();

    expect($prices)->toHaveCount(1)
        ->and($prices->first()->unit_price_minor)->toBe(82_000);
});

// -------------------------------------------------------------------- quotes

test('a quote is created with a quotable reference and computed totals', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('quotes.store'), quotePayload($fixture))
        ->assertRedirect();

    $quote = Quote::query()->sole();

    expect($quote->reference)->toStartWith('ORC '.now()->year)
        ->and($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->net_total_minor)->toBe(10_000_000)
        ->and($quote->tax_total_minor)->toBe(1_400_000)
        ->and($quote->gross_total_minor)->toBe(11_400_000)
        ->and($quote->lines)->toHaveCount(1);
});

test('a quote applies line discounts before tax', function () {
    $fixture = billingFixture();
    $payload = quotePayload($fixture, '1000.00');
    $payload['lines'][0]['discount_rate'] = '10';

    $this->actingAs($fixture['owner'])
        ->post(route('quotes.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $quote = Quote::query()->with('lines')->sole();

    expect($quote->lines->first()->discount_rate_basis_points)->toBe(1000)
        ->and($quote->net_total_minor)->toBe(90_000)
        ->and($quote->tax_total_minor)->toBe(12_600)
        ->and($quote->gross_total_minor)->toBe(102_600);
});

test('each legal entity can use its own quote reference sequence', function () {
    $firstFixture = billingFixture();
    $secondFixture = billingFixture();

    $this->actingAs($firstFixture['owner'])
        ->post(route('quotes.store'), quotePayload($firstFixture))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($secondFixture['owner'])
        ->post(route('quotes.store'), quotePayload($secondFixture))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $quotes = Quote::query()->oldest('id')->get();

    expect($quotes)->toHaveCount(2)
        ->and($quotes->pluck('reference')->all())->toBe([
            'ORC '.now()->year.'/0001',
            'ORC '.now()->year.'/0001',
        ])
        ->and($quotes->pluck('legal_entity_id')->unique())->toHaveCount(2);
});

test('deleting an older draft does not reuse an existing quote reference', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('quotes.store'), quotePayload($fixture))
        ->assertRedirect();

    $this->post(route('quotes.store'), quotePayload($fixture))->assertRedirect();

    Quote::query()->oldest('id')->firstOrFail()->delete();

    $this->post(route('quotes.store'), quotePayload($fixture))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Quote::query()->oldest('id')->pluck('reference')->all())->toBe([
        'ORC '.now()->year.'/0002',
        'ORC '.now()->year.'/0003',
    ]);
});

test('a quote is not a fiscal document and never reaches the AGT', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));

    // Nothing fiscal exists until somebody converts it.
    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a quote can be marked sent, then accepted', function () {
    $fixture = billingFixture();
    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));
    $quote = Quote::query()->sole();

    $this->put(route('quotes.transition', $quote), ['status' => 'sent'])
        ->assertRedirect();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);

    $this->put(route('quotes.transition', $quote), ['status' => 'accepted']);

    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted);
});

test('a quote cannot skip the sent working-document state', function () {
    $fixture = billingFixture();
    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));
    $quote = Quote::query()->sole();

    $this->put(route('quotes.transition', $quote), ['status' => 'accepted'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft)
        ->and($quote->fresh()->sent_at)->toBeNull();
});

test('a quote past its validity reads as expired without a job having run', function () {
    $fixture = billingFixture();
    $quote = Quote::factory()->lapsed()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
    ]);

    expect($quote->effectiveStatus())->toBe(QuoteStatus::Expired)
        ->and($quote->hasLapsed())->toBeTrue();
});

test('an accepted quote becomes an invoice draft, not an issued invoice', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));
    $quote = Quote::query()->sole();
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    $document = app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);

    $line = $document->lines()->with('taxes')->sole();

    expect($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($document->document_no)->toBeNull()
        ->and($document->gross_total_minor)->toBe(11_400_000)
        ->and($document->lines()->count())->toBe(1)
        ->and($line->unit_price_micros)->toBe(100_000_000_000)
        ->and($line->taxes->sole()->tax_code)->toBe('NOR')
        ->and($quote->fresh()->status)->toBe(QuoteStatus::Converted);
});

test('quote discounts and fiscal rounding survive invoice conversion exactly', function () {
    $fixture = billingFixture();
    $payload = quotePayload($fixture, '100000.00');
    $payload['lines'][0]['quantity'] = '2';
    $payload['lines'][0]['discount_rate'] = '10';

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), $payload);
    $quote = Quote::query()->sole();
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    $document = app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);
    $line = $document->lines()->with('taxes')->sole();

    expect($quote->net_total_minor)->toBe(18_000_000)
        ->and($quote->tax_total_minor)->toBe(2_520_000)
        ->and($quote->gross_total_minor)->toBe(20_520_000)
        ->and($document->settlement_total_minor)->toBe(2_000_000)
        ->and($document->net_total_minor)->toBe(18_000_000)
        ->and($document->tax_payable_minor)->toBe(2_520_000)
        ->and($document->gross_total_minor)->toBe(20_520_000)
        ->and($line->unit_price_micros)->toBe(90_000_000_000)
        ->and($line->settlement_amount_minor)->toBe(2_000_000)
        ->and($line->taxes->sole()->tax_code)->toBe('NOR');
});

test('quote tax uses the same upward-cent rounding as a fiscal document', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('quotes.store'), quotePayload($fixture, '0.01'))
        ->assertSessionHasNoErrors();

    $quote = Quote::query()->sole();
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();
    $document = app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);

    expect($quote->net_total_minor)->toBe(1)
        ->and($quote->tax_total_minor)->toBe(1)
        ->and($quote->gross_total_minor)->toBe(2)
        ->and($document->tax_payable_minor)->toBe(1)
        ->and($document->gross_total_minor)->toBe(2);
});

test('the converted invoice falls due on the customer terms', function () {
    $fixture = billingFixture(termsDays: 60);

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));
    $quote = Quote::query()->sole();
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    $document = app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);

    expect($document->due_date->toDateString())
        ->toBe(now('Africa/Luanda')->addDays(60)->toDateString());
});

test('a quote cannot be converted twice', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));
    $quote = Quote::query()->sole();
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);

    expect(fn () => app(ConvertQuoteToInvoice::class)->execute($quote->fresh(), $fixture['owner']))
        ->toThrow(RuntimeException::class);
});

test('a draft quote cannot be converted before it has been sent', function () {
    $fixture = billingFixture();

    $this->actingAs($fixture['owner'])->post(route('quotes.store'), quotePayload($fixture));

    expect(fn () => app(ConvertQuoteToInvoice::class)->execute(
        Quote::query()->sole(),
        $fixture['owner'],
    ))->toThrow(RuntimeException::class);
});

test('a quote to a prospect with no NIF is refused before it reaches the invoice', function () {
    $fixture = billingFixture();
    $quote = Quote::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => null,
        'created_by_user_id' => $fixture['owner']->id,
        'status' => QuoteStatus::Draft,
        'customer_name' => 'Cooperativa sem NIF',
        'customer_tax_identification_number' => null,
    ]);

    $quote->lines()->create([
        'workspace_id' => $quote->workspace_id,
        'legal_entity_id' => $quote->legal_entity_id,
        'line_number' => 1,
        'operation_type' => 'SG',
        'product_description' => 'Consultoria',
        'quantity_units' => 1_000,
        'quantity_scale' => 3,
        'unit_of_measure' => 'UN',
        'unit_price_minor' => 100_000,
        'discount_rate_basis_points' => 0,
        'tax_type' => 'IVA',
        'tax_code' => 'NOR',
        'tax_percentage' => '14.00',
        'net_amount_minor' => 100_000,
        'tax_amount_minor' => 14_000,
        'gross_amount_minor' => 114_000,
    ]);
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    expect(fn () => app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']))
        ->toThrow(BillingActionRefused::class, 'Indique o NIF do cliente no orçamento antes de o passar a factura.')
        ->and(FiscalDocument::query()->count())->toBe(0);
});

test('a legacy quote line reaches the draft with a product code and canonical tax code', function () {
    $fixture = billingFixture();
    $quote = Quote::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'status' => QuoteStatus::Draft,
        'customer_name' => $fixture['customer']->name,
        'customer_tax_identification_number' => $fixture['customer']->tax_identification_number,
        'net_total_minor' => 100_000,
        'tax_total_minor' => 14_000,
        'gross_total_minor' => 114_000,
    ]);

    $quote->lines()->create([
        'workspace_id' => $quote->workspace_id,
        'legal_entity_id' => $quote->legal_entity_id,
        'line_number' => 1,
        'operation_type' => 'SG',
        'product_code' => null,
        'product_description' => 'Consultoria de implementação',
        'quantity_units' => 1_000,
        'quantity_scale' => 3,
        'unit_of_measure' => 'UN',
        'unit_price_minor' => 100_000,
        'discount_rate_basis_points' => 0,
        'tax_type' => 'IVA',
        'tax_code' => null,
        'tax_percentage' => '14.00',
        'net_amount_minor' => 100_000,
        'tax_amount_minor' => 14_000,
        'gross_amount_minor' => 114_000,
    ]);
    $quote->forceFill(['status' => QuoteStatus::Accepted])->save();

    $document = app(ConvertQuoteToInvoice::class)->execute($quote, $fixture['owner']);

    $line = $document->lines()->with('taxes')->sole();

    expect($line->product_code)->toBe('CONSULTORIA-DE-IMPLEMENTACAO')
        ->and($line->taxes->sole()->tax_code)->toBe('NOR')
        ->and($line->taxes->sole()->tax_rate_basis_points)->toBe(1_400);
});

test('a quote rejects a tax combination that cannot be issued', function () {
    $fixture = billingFixture();
    $payload = quotePayload($fixture);
    $payload['lines'][0]['tax_code'] = 'ISE';
    $payload['lines'][0]['tax_percentage'] = '14';
    $payload['lines'][0]['tax_exemption_code'] = 'M02';

    $this->actingAs($fixture['owner'])
        ->post(route('quotes.store'), $payload)
        ->assertSessionHasErrors('lines.0.tax_percentage');

    expect(Quote::query()->doesntExist())->toBeTrue();
});

test('a converted quote can no longer be edited', function () {
    $fixture = billingFixture();
    $quote = Quote::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => QuoteStatus::Converted,
    ]);

    $this->actingAs($fixture['owner'])
        ->put(route('quotes.update', $quote), quotePayload($fixture))
        ->assertSessionHas('error');
});

// ------------------------------------------------------------ debt management

test('aging counts from the due date, not from the invoice date', function () {
    $fixture = billingFixture();

    // Issued 100 days ago on 90-day terms: only 10 days late.
    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_date' => now()->subDays(100)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
        'gross_total_minor' => 500_000,
    ]);

    $rows = app(AgingQuery::class)->byCustomer($fixture['legalEntity']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['buckets']['d1_30'])->toBe(500_000)
        ->and($rows[0]['buckets']['d90_plus'])->toBe(0)
        ->and($rows[0]['oldest_days_past_due'])->toBe(10);
});

test('an invoice not yet due sits in the current bucket', function () {
    $fixture = billingFixture();

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_date' => now()->toDateString(),
        'due_date' => now()->addDays(20)->toDateString(),
        'gross_total_minor' => 400_000,
    ]);

    $rows = app(AgingQuery::class)->byCustomer($fixture['legalEntity']);

    expect($rows[0]['buckets']['current'])->toBe(400_000)
        ->and($rows[0]['overdue_minor'])->toBe(0);
});

test('the debts page flags a customer over their credit limit', function () {
    $fixture = billingFixture();
    $fixture['customer']->forceFill(['credit_limit_minor' => 100_000])->save();

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'gross_total_minor' => 500_000,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('debts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Debts/Index')
            ->where('summary.over_limit_count', 1)
            ->where('customers.0.over_limit', true)
        );
});

// ------------------------------------------------------------ email delivery

test('an issued document can be emailed to the customer', function () {
    Notification::fake();

    $fixture = billingFixture();
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/9',
        'gross_total_minor' => 500_000,
    ]);

    app(SendFiscalDocumentToCustomer::class)->execute($document, $fixture['owner']);

    Notification::assertSentOnDemand(FiscalDocumentIssued::class);

    expect($document->fresh()->sent_to_email)->toBe('financeiro@kwanza.ao')
        ->and($document->fresh()->send_count)->toBe(1);
});

test('the customer profile offers a send control only where there is a number to send', function () {
    $fixture = billingFixture();

    // Both are past draft, but only one carries a fiscal number.
    foreach (['FT 2026/11', null] as $number) {
        FiscalDocument::factory()->create([
            'workspace_id' => $fixture['legalEntity']->workspace_id,
            'legal_entity_id' => $fixture['legalEntity']->id,
            'establishment_id' => $fixture['establishment']->id,
            'customer_id' => $fixture['customer']->id,
            'document_type' => FiscalDocumentType::Invoice,
            'status' => FiscalDocumentStatus::Valid,
            'document_no' => $number,
        ]);
    }

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['documents']['data'])
                ->keyBy(fn (array $row): string => $row['document_no'] ?? 'unnumbered');

            expect($rows['FT 2026/11']['can_send'])->toBeTrue()
                ->and($rows['FT 2026/11']['sent_at'])->toBeNull()
                ->and($rows['FT 2026/11']['send_count'])->toBe(0)
                ->and($rows['unnumbered']['can_send'])->toBeFalse();
        });
});

test('a draft is never emailed to the customer', function () {
    Notification::fake();

    $fixture = billingFixture();
    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => FiscalDocumentStatus::Draft,
    ]);

    // A draft has no number and no AGT acknowledgement; sending one invites
    // the customer to pay against something that does not exist yet.
    expect(fn () => app(SendFiscalDocumentToCustomer::class)->execute($draft))
        ->toThrow(RuntimeException::class);

    Notification::assertNothingSent();
});

test('a customer without an email is refused rather than silently skipped', function () {
    $fixture = billingFixture();
    $fixture['customer']->forceFill(['email' => null])->save();

    $document = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => FiscalDocumentStatus::Valid,
    ]);

    expect(fn () => app(SendFiscalDocumentToCustomer::class)->execute($document->fresh()))
        ->toThrow(RuntimeException::class);
});

test('only customers set up for it are sent documents automatically', function () {
    $fixture = billingFixture();
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => FiscalDocumentStatus::Valid,
    ]);

    $send = app(SendFiscalDocumentToCustomer::class);

    expect($send->shouldSendAutomatically($document->fresh()))->toBeFalse();

    $fixture['customer']->forceFill(['auto_send_documents' => true])->save();

    expect($send->shouldSendAutomatically($document->fresh()->load('customer')))->toBeTrue();
});

// --------------------------------------------------------- recurring invoices

test('a due profile raises a draft dated to the period', function () {
    $fixture = billingFixture();

    RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'starts_on' => now()->toDateString(),
        'next_run_on' => now()->toDateString(),
    ]);

    $result = app(GenerateRecurringInvoices::class)->execute();

    expect($result['generated'])->toBe(1);

    $document = FiscalDocument::query()->sole();

    $line = $document->lines()->with('taxes')->sole();

    expect($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($document->gross_total_minor)->toBe(5_700_000)
        ->and($document->lines()->count())->toBe(1)
        ->and($line->unit_price_micros)->toBe(50_000_000_000)
        ->and($line->taxes->sole()->tax_code)->toBe('NOR');
});

test('a recurring profile normalises legacy tax data and rounds tax upward', function () {
    $fixture = billingFixture();

    RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'starts_on' => now()->toDateString(),
        'next_run_on' => now()->toDateString(),
        'lines' => [[
            'product_code' => 'MICRO-01',
            'operation_type' => 'SG',
            'product_description' => 'Linha mínima',
            'unit_of_measure' => 'UN',
            'quantity_units' => 1_000,
            'quantity_scale' => 3,
            'unit_price_minor' => 1,
            'discount_rate_basis_points' => 0,
            'tax_type' => 'IVA',
            'tax_code' => null,
            'tax_percentage' => '14.00',
            'tax_exemption_code' => null,
        ]],
    ]);

    app(GenerateRecurringInvoices::class)->execute();

    $document = FiscalDocument::query()->sole();
    $tax = $document->lines()->with('taxes')->sole()->taxes->sole();

    expect($document->net_total_minor)->toBe(1)
        ->and($document->tax_payable_minor)->toBe(1)
        ->and($document->gross_total_minor)->toBe(2)
        ->and($tax->tax_code)->toBe('NOR');
});

test('the schedule advances by the chosen frequency', function () {
    $fixture = billingFixture();

    $profile = RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'frequency' => RecurrenceFrequency::Monthly,
        'starts_on' => now()->toDateString(),
        'next_run_on' => now()->toDateString(),
    ]);

    app(GenerateRecurringInvoices::class)->execute();

    expect($profile->fresh()->next_run_on->toDateString())
        ->toBe(now()->addMonthNoOverflow()->toDateString())
        ->and($profile->fresh()->generated_count)->toBe(1);
});

test('a profile that fell behind catches up one document per missed period', function () {
    $fixture = billingFixture();

    // Three months overdue: three documents owed, not one lump sum.
    RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'frequency' => RecurrenceFrequency::Monthly,
        'starts_on' => now()->subMonths(3)->toDateString(),
        'next_run_on' => now()->subMonths(3)->toDateString(),
    ]);

    $result = app(GenerateRecurringInvoices::class)->execute();

    expect($result['generated'])->toBe(4)
        ->and(FiscalDocument::query()->count())->toBe(4);
});

test('a profile past its end date stops and deactivates itself', function () {
    $fixture = billingFixture();

    $profile = RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'starts_on' => now()->subMonths(2)->toDateString(),
        'next_run_on' => now()->subDay()->toDateString(),
        'ends_on' => now()->subMonth()->toDateString(),
    ]);

    app(GenerateRecurringInvoices::class)->execute();

    expect($profile->fresh()->is_active)->toBeFalse()
        ->and(FiscalDocument::query()->count())->toBe(0);
});

test('an inactive profile generates nothing', function () {
    $fixture = billingFixture();

    RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'is_active' => false,
        'next_run_on' => now()->subWeek()->toDateString(),
    ]);

    expect(app(GenerateRecurringInvoices::class)->execute()['generated'])->toBe(0);
});

test('a monthly profile on the 31st does not skip February', function () {
    $january = CarbonImmutable::parse('2026-01-31');

    // Overflowing would land in March and drift further every year.
    expect(RecurrenceFrequency::Monthly->next($january)->toDateString())
        ->toBe('2026-02-28');
});

test('the recurring page lists profiles and what they will bill', function () {
    $fixture = billingFixture();

    RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'name' => 'Avença de manutenção',
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('recurring.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recurring/Index')
            ->where('profiles.0.name', 'Avença de manutenção')
            ->where('profiles.0.estimated_total_minor', 5_700_000)
            ->where('profiles.0.auto_issue', false)
        );
});

test('stopping a profile keeps it for the record rather than deleting it', function () {
    $fixture = billingFixture();

    $profile = RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'created_by_user_id' => $fixture['owner']->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->delete(route('recurring.destroy', $profile))
        ->assertRedirect();

    expect(RecurringInvoice::query()->count())->toBe(1)
        ->and($profile->fresh()->is_active)->toBeFalse();
});

test('another workspace cannot touch these profiles', function () {
    $fixture = billingFixture();
    $profile = RecurringInvoice::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
    ]);

    $intruder = User::factory()->withWorkspace('Intruso')->create();
    LegalEntity::factory()->configured()->create([
        'workspace_id' => $intruder->currentWorkspace()->firstOrFail()->id,
        'tax_identification_number' => '5000000888',
    ]);

    $this->actingAs($intruder)
        ->delete(route('recurring.destroy', $profile))
        ->assertNotFound();

    expect($profile->fresh()->is_active)->toBeTrue();
});
