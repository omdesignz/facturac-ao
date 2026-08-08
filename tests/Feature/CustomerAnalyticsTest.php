<?php

use App\Analytics\AnalyticsPeriod;
use App\Analytics\ReceivablesQuery;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use App\Models\LegalEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function analyticsFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Análises')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5000000123',
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Kwanza Mercantil, Lda.',
        'tax_identification_number' => '5411111111',
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'customer');
}

/**
 * @param  array<string, mixed>  $fixture
 */
function issueDocument(
    array $fixture,
    FiscalDocumentType $type,
    int $grossMinor,
    ?string $date = null,
    ?string $dueDate = null,
    int $taxMinor = 0,
    ?FiscalDocument $references = null,
): FiscalDocument {
    return FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => $type,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => $type->value.' 2026/'.fake()->unique()->numberBetween(1, 9999),
        'document_date' => $date ?? now()->toDateString(),
        'due_date' => $dueDate,
        'gross_total_minor' => $grossMinor,
        'net_total_minor' => $grossMinor - $taxMinor,
        'tax_payable_minor' => $taxMinor,
        // Set here rather than after the fact: an issued document is immutable,
        // which is exactly the behaviour being relied on elsewhere.
        'references_document_id' => $references?->id,
        'references_document_no' => $references?->document_no,
    ]);
}

function settle(FiscalDocument $receipt, FiscalDocument $invoice, int $amountMinor): void
{
    FiscalDocumentSettlement::query()->create([
        'workspace_id' => $invoice->workspace_id,
        'legal_entity_id' => $invoice->legal_entity_id,
        'fiscal_document_id' => $receipt->id,
        'settled_document_id' => $invoice->id,
        'settled_document_no' => $invoice->document_no,
        'amount_minor' => $amountMinor,
    ]);
}

/** Reloads a document with the balance subqueries attached. */
function withBalances(FiscalDocument $document): FiscalDocument
{
    return app(ReceivablesQuery::class)
        ->withBalances(FiscalDocument::query()->whereKey($document->id))
        ->sole();
}

// ------------------------------------------------------- receivable arithmetic

test('an unpaid invoice is outstanding in full', function () {
    $fixture = analyticsFixture();
    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);

    $receivables = app(ReceivablesQuery::class);

    expect($receivables->outstandingMinor(withBalances($invoice)))->toBe(500_000)
        ->and($receivables->paidMinor(withBalances($invoice)))->toBe(0);
});

test('a receipt reduces what the invoice still owes', function () {
    $fixture = analyticsFixture();
    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    $receipt = issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 0);

    settle($receipt, $invoice, 200_000);

    $receivables = app(ReceivablesQuery::class);

    expect($receivables->outstandingMinor(withBalances($invoice)))->toBe(300_000)
        ->and($receivables->paidMinor(withBalances($invoice)))->toBe(200_000);
});

test('an invoice-receipt is paid the moment it is issued', function () {
    $fixture = analyticsFixture();
    $document = issueDocument($fixture, FiscalDocumentType::InvoiceReceipt, 750_000);

    $receivables = app(ReceivablesQuery::class);

    expect($receivables->outstandingMinor(withBalances($document)))->toBe(0)
        ->and($receivables->paidMinor(withBalances($document)))->toBe(750_000);
});

test('a credit note reduces the invoice it references', function () {
    $fixture = analyticsFixture();
    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);

    issueDocument($fixture, FiscalDocumentType::CreditNote, 150_000, references: $invoice);

    expect(app(ReceivablesQuery::class)->outstandingMinor(withBalances($invoice)))
        ->toBe(350_000);
});

test('an invoice paid and credited to zero is not left owing a negative', function () {
    $fixture = analyticsFixture();
    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    $receipt = issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 0);

    settle($receipt, $invoice, 400_000);
    issueDocument($fixture, FiscalDocumentType::CreditNote, 200_000, references: $invoice);

    expect(app(ReceivablesQuery::class)->outstandingMinor(withBalances($invoice)))
        ->toBe(0);
});

test('a standalone receipt is not itself a receivable', function () {
    $fixture = analyticsFixture();
    $receipt = issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 300_000);

    expect(app(ReceivablesQuery::class)->outstandingMinor(withBalances($receipt)))
        ->toBe(0);
});

test('a draft is neither billed nor owed', function () {
    $fixture = analyticsFixture();

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => FiscalDocumentStatus::Draft,
        'gross_total_minor' => 999_999,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.billed_minor', 0)
            ->where('summary.document_count', 0)
        );
});

test('an overdue invoice is counted only while money remains on it', function () {
    $fixture = analyticsFixture();

    $overdue = issueDocument(
        $fixture,
        FiscalDocumentType::Invoice,
        400_000,
        date: now()->subMonths(2)->toDateString(),
        dueDate: now()->subMonth()->toDateString(),
    );

    $receivables = app(ReceivablesQuery::class);

    expect($receivables->isOverdue(withBalances($overdue)))->toBeTrue();

    $receipt = issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 0);
    settle($receipt, $overdue, 400_000);

    expect($receivables->isOverdue(withBalances($overdue)))->toBeFalse();
});

test('an invoice with no due date is never overdue', function () {
    $fixture = analyticsFixture();

    $invoice = issueDocument(
        $fixture,
        FiscalDocumentType::Invoice,
        400_000,
        date: now()->subYear()->toDateString(),
        dueDate: null,
    );

    expect(app(ReceivablesQuery::class)->isOverdue(withBalances($invoice)))
        ->toBeFalse();
});

test('a credit note reverses tax as well as value', function () {
    $fixture = analyticsFixture();

    issueDocument($fixture, FiscalDocumentType::Invoice, 1_140_000, taxMinor: 140_000);
    issueDocument($fixture, FiscalDocumentType::CreditNote, 114_000, taxMinor: 14_000);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.billed_minor', 1_140_000)
            ->where('summary.credited_minor', 114_000)
            // Leaving the tax un-reversed would overstate what is owed to the AGT.
            ->where('summary.tax_minor', 126_000)
            ->where('summary.outstanding_minor', 1_026_000)
        );
});

// ------------------------------------------------------------ customer profile

test('the profile shows every document issued to that customer', function () {
    $fixture = analyticsFixture();

    issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    issueDocument($fixture, FiscalDocumentType::InvoiceReceipt, 250_000);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.name', 'Kwanza Mercantil, Lda.')
            ->where('summary.billed_minor', 750_000)
            ->where('summary.paid_minor', 250_000)
            ->where('summary.outstanding_minor', 500_000)
            ->has('documents.data', 2)
            ->has('trend', 12)
        );
});

test('another customer documents never leak onto this profile', function () {
    $fixture = analyticsFixture();
    $other = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Outro Cliente',
    ]);

    issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $other->id,
        'status' => FiscalDocumentStatus::Valid,
        'gross_total_minor' => 999_999,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents.data', 1)
            ->where('summary.billed_minor', 500_000)
        );
});

test('a customer from another workspace cannot be opened', function () {
    $fixture = analyticsFixture();
    $intruder = User::factory()->withWorkspace('Intruso')->create();
    LegalEntity::factory()->configured()->create([
        'workspace_id' => $intruder->currentWorkspace()->firstOrFail()->id,
        'tax_identification_number' => '5000000999',
    ]);

    $this->actingAs($intruder)
        ->get(route('customers.show', $fixture['customer']))
        ->assertForbidden();
});

test('the twelve-month trend compares against the same month a year earlier', function () {
    $fixture = analyticsFixture();
    $now = CarbonImmutable::now('Africa/Luanda');

    issueDocument($fixture, FiscalDocumentType::Invoice, 300_000, date: $now->toDateString());
    issueDocument(
        $fixture,
        FiscalDocumentType::Invoice,
        100_000,
        date: $now->subYear()->toDateString(),
    );

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(function (Assert $page) {
            $trend = $page->toArray()['props']['trend'];
            $lastMonth = $trend[count($trend) - 1];

            expect($lastMonth['value'])->toBe(300_000)
                ->and($lastMonth['comparison'])->toBe(100_000);
        });
});

// ----------------------------------------------------------------- analytics

test('the analytics page reports the period against the one before it', function () {
    $fixture = analyticsFixture();
    $now = CarbonImmutable::now('Africa/Luanda');

    issueDocument($fixture, FiscalDocumentType::Invoice, 800_000, date: $now->toDateString());
    issueDocument(
        $fixture,
        FiscalDocumentType::Invoice,
        400_000,
        date: $now->subMonth()->startOfMonth()->addDay()->toDateString(),
    );

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index', ['period' => 'this_month']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Index')
            ->where('summary.billed_minor', 800_000)
            ->where('previousSummary.billed_minor', 400_000)
            ->where('period.key', 'this_month')
        );
});

test('an unknown period falls back to this month rather than erroring', function () {
    $fixture = analyticsFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index', ['period' => 'since-the-dawn-of-time']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('period.key', 'this_month'));
});

test('every calendar bucket is plotted, including the empty ones', function () {
    $fixture = analyticsFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index', ['period' => 'last_12_months']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            // A month with no invoices must show as zero, not vanish and let
            // the line pretend the gap was never there.
            expect($page->toArray()['props']['trend'])->toHaveCount(12);
        });
});

test('top customers are ranked by what they were billed', function () {
    $fixture = analyticsFixture();
    $small = Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Cliente Pequeno',
    ]);

    issueDocument($fixture, FiscalDocumentType::Invoice, 900_000);
    issueDocument(
        [...$fixture, 'customer' => $small],
        FiscalDocumentType::Invoice,
        100_000,
    );

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('topCustomers.0.label', 'Kwanza Mercantil, Lda.')
            ->where('topCustomers.0.value', 900_000)
            ->where('topCustomers.1.label', 'Cliente Pequeno')
        );
});

test('receipts are excluded from billed revenue so payment is not double counted', function () {
    $fixture = analyticsFixture();

    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    $receipt = issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 500_000);
    settle($receipt, $invoice, 500_000);

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.billed_minor', 500_000)
            ->where('summary.paid_minor', 500_000)
            ->where('summary.outstanding_minor', 0)
        );
});

test('the period options are offered to the interface', function () {
    $fixture = analyticsFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('periods', count(AnalyticsPeriod::options()))
            ->has('operations.acceptance_rate')
            ->has('operations.drafts_open')
        );
});

test('a company without a fiscal identity is sent to onboarding first', function () {
    $owner = User::factory()->withWorkspace('Sem identidade')->create();

    $this->actingAs($owner)
        ->get(route('analytics.index'))
        ->assertRedirect(route('onboarding'));
});

test('a receipt is not shown with a due date it cannot have', function () {
    $fixture = analyticsFixture();

    issueDocument(
        $fixture,
        FiscalDocumentType::IssuedReceipt,
        0,
        dueDate: now()->addDays(30)->toDateString(),
    );

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.data.0.due_date', null)
            ->where('documents.data.0.is_billable', false)
        );
});

test('the value mix leaves out types that carry no value of their own', function () {
    $fixture = analyticsFixture();

    issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    issueDocument($fixture, FiscalDocumentType::IssuedReceipt, 0);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(function (Assert $page) {
            $labels = collect($page->toArray()['props']['typeMix'])->pluck('label');

            // A zero-length bar on a value chart reads as "worth nothing"
            // rather than "not measured here".
            expect($labels)->toContain('Factura')
                ->and($labels)->not->toContain('Recibo emitido');
        });
});

test('a credit note applied to an invoice is not subtracted twice', function () {
    $fixture = analyticsFixture();
    $invoice = issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);

    issueDocument($fixture, FiscalDocumentType::CreditNote, 100_000, references: $invoice);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        // The credit is already netted off the invoice it names. Taking it off
        // the total again would report 300 000 and understate the debt.
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.outstanding_minor', 400_000)
            ->where('summary.credited_minor', 100_000)
        );
});

test('a credit note tied to no invoice still comes off the total', function () {
    $fixture = analyticsFixture();

    issueDocument($fixture, FiscalDocumentType::Invoice, 500_000);
    issueDocument($fixture, FiscalDocumentType::CreditNote, 100_000);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.outstanding_minor', 400_000)
        );
});

test('the summary is one grouped query however many documents there are', function () {
    $fixture = analyticsFixture();

    foreach (range(1, 30) as $ignored) {
        issueDocument($fixture, FiscalDocumentType::Invoice, 10_000);
    }

    DB::enableQueryLog();
    app(ReceivablesQuery::class)->summarise(
        ReceivablesQuery::issued($fixture['customer']->fiscalDocuments()->getQuery()),
    );
    $queries = DB::getRawQueryLog();
    DB::disableQueryLog();

    // Aggregating in PHP would have meant loading all thirty rows; this must
    // stay a single round trip whatever the volume.
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['raw_query'])->toContain('sum(');
});

test('the document table is paged rather than loaded whole', function () {
    $fixture = analyticsFixture();

    foreach (range(1, 30) as $ignored) {
        issueDocument($fixture, FiscalDocumentType::Invoice, 10_000);
    }

    $this->actingAs($fixture['owner'])
        ->get(route('customers.show', $fixture['customer']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents.data', 25)
            ->where('documents.total', 30)
            // The summary still covers all thirty, not just the page.
            ->where('summary.billed_minor', 300_000)
        );
});
