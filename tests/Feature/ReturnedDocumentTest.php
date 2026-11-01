<?php

use App\Actions\CreateMobileInvoice;
use App\Actions\PreparePaymentReminder;
use App\Actions\SendPaymentReminder;
use App\Actions\SignInMobileDevice;
use App\Analytics\AgingQuery;
use App\Analytics\ReceivablesQuery;
use App\Exceptions\BillingActionRefused;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\WorkspaceMembership;
use App\Notifications\PaymentReminder;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

/**
 * @return array<string, mixed>
 */
function returnedCompany(): array
{
    $company = posCompany();
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'FTRET',
        'document_type' => FiscalDocumentType::Invoice,
    ]);

    return [
        ...$company,
        'token' => $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'customer' => Customer::factory()->create([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
            'name' => 'Lobito Construções',
            'email' => 'contas@lobito.ao',
            'tax_identification_number' => '5417000001',
        ]),
        'item' => posItem($company),
    ];
}

/**
 * An issued invoice, past due, with the AGT's answer recorded as the gateway gives it.
 *
 * @param  array<string, mixed>  $company
 */
function returnedInvoice(array $company, ?string $agtAnswer = 'I'): FiscalDocument
{
    $invoice = app(CreateMobileInvoice::class)->execute($company['legalEntity'], $company['owner'], [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => 22_800,
        'issue' => true,
    ]);

    $document = $invoice->fiscalDocument;

    if ($agtAnswer !== null) {
        recordAuthoritativeAgtAcceptance($document, $agtAnswer);
    }

    FiscalDocument::query()->whereKey($document->id)->toBase()->update(['due_date' => now()->subDays(10)->toDateString()]);

    return $document->fresh();
}

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

test('an invoice the agt reported invalid is owed by nobody', function () {
    $company = returnedCompany();
    returnedInvoice($company);

    $summary = app(ReceivablesQuery::class)->summarise(app(ReceivablesQuery::class)->forLegalEntity($company['legalEntity']));

    expect($summary['outstanding_minor'])->toBe(0)
        ->and($summary['overdue_minor'])->toBe(0)
        ->and($summary['overdue_count'])->toBe(0);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.receivables.outstanding_minor', 0)
        ->assertJsonPath('data.debtors', []);

    $this->withToken($company['token'])->getJson(route('mobile.v1.collections'))
        ->assertJsonPath('data.outstanding_minor', 0)
        ->assertJsonPath('data.customers', []);
});

test('it stays in what was billed and on record until the agt says how to replace it', function () {
    $company = returnedCompany();
    $document = returnedInvoice($company);

    $summary = app(ReceivablesQuery::class)->summarise(app(ReceivablesQuery::class)->forLegalEntity($company['legalEntity']));

    expect($summary['billed_minor'])->toBe(22_800)
        ->and($summary['document_count'])->toBe(1)
        ->and($document->document_no)->not->toBeNull();

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.index'))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.tone', 'critical');
});

test('an accepted invoice is still owed', function () {
    $company = returnedCompany();
    returnedInvoice($company, 'V');

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.receivables.outstanding_minor', 22_800);
});

test('an invoice the agt has not answered for is still owed', function () {
    $company = returnedCompany();
    returnedInvoice($company, null);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.receivables.outstanding_minor', 22_800);
});

test('no reminder can be written or sent for it', function () {
    $company = returnedCompany();
    returnedInvoice($company);

    $draft = app(PreparePaymentReminder::class)->execute($company['customer']);

    expect($draft['documents'])->toBe([])->and($draft['total_minor'])->toBe(0);

    expect(fn () => app(SendPaymentReminder::class)->execute($company['customer'], 'Lembrete', 'Bom dia, segue o lembrete de pagamento.'))
        ->toThrow(BillingActionRefused::class);

    Notification::assertSentOnDemandTimes(PaymentReminder::class, 0);
});

test('the statement lists it without adding it to the balance', function () {
    $company = returnedCompany();
    $returned = returnedInvoice($company);
    $accepted = returnedInvoice($company, 'V');

    $aging = app(AgingQuery::class);
    $rows = collect($aging->statement($aging->forCustomer($company['customer'])));

    expect($rows->firstWhere('public_id', $returned->public_id)['movement_minor'])->toBe(0)
        ->and($rows->firstWhere('public_id', $accepted->public_id)['movement_minor'])->toBe(22_800)
        ->and($rows->last()['balance_minor'])->toBe(22_800);
});

test('the phone is told it was returned and has nothing to remind about', function () {
    $company = returnedCompany();
    $document = returnedInvoice($company);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $document->public_id))
        ->assertOk()
        ->assertJsonPath('data.is_returned', true)
        ->assertJsonPath('data.tone', 'critical')
        ->assertJsonPath('data.outstanding_minor', 0)
        ->assertJsonPath('data.can_remind', false)
        ->assertJsonPath('data.share_url', null)
        ->assertJsonPath('data.customer_tax_identification_number', '5417000001');
});

test('an accepted invoice can still be shared and reminded about', function () {
    $company = returnedCompany();
    $document = returnedInvoice($company, 'V');

    $response = $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $document->public_id))
        ->assertJsonPath('data.is_returned', false)
        ->assertJsonPath('data.can_remind', true);

    expect($response->json('data.share_url'))->toContain('signature=');
});

test('correcting the nif changes the customer and never the signed document', function () {
    $company = returnedCompany();
    $document = returnedInvoice($company);

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.tax-identification', $company['customer']->public_id), [
            'tax_identification_number' => ' 5417000002 ',
        ])
        ->assertOk()
        ->assertJsonPath('data.tax_identification_number', '5417000002');

    expect($company['customer']->fresh()->tax_identification_number)->toBe('5417000002')
        ->and($document->fresh()->customer_tax_identification_number)->toBe('5417000001');

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $document->public_id))
        ->assertJsonPath('data.customer_tax_identification_number', '5417000002');
});

test('a nif another customer already has is refused', function () {
    $company = returnedCompany();
    Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'tax_identification_number' => '5417000009',
    ]);

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.tax-identification', $company['customer']->public_id), [
            'tax_identification_number' => '5417000009',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_identification_number']);
});

test('keeping the same nif is not a clash with itself', function () {
    $company = returnedCompany();

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.tax-identification', $company['customer']->public_id), [
            'tax_identification_number' => '5417000001',
        ])
        ->assertOk();
});

test('a viewer cannot change a customer', function () {
    $company = returnedCompany();
    WorkspaceMembership::query()->where('user_id', $company['owner']->id)->update(['role' => WorkspaceRole::Viewer]);

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.tax-identification', $company['customer']->public_id), [
            'tax_identification_number' => '5417000002',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CUSTOMER_NOT_ALLOWED');

    expect($company['customer']->fresh()->tax_identification_number)->toBe('5417000001');
});

test('another company\'s customer is not found', function () {
    $company = returnedCompany();
    $other = Customer::factory()->create();

    $this->withToken($company['token'])
        ->patchJson(route('mobile.v1.customers.tax-identification', $other->public_id), [
            'tax_identification_number' => '5417000002',
        ])
        ->assertNotFound();
});
