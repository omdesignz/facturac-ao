<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\SubmitAgtDocument;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\FiscalDocument;
use App\Models\MobileInvoice;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

/**
 * 2 × 100,00 at 14 % IVA: 200,00 net, 28,00 tax, 228,00 to pay.
 */
const PHONE_INVOICE_GROSS = 22_800;

/**
 * @return array<string, mixed>
 */
function phoneInvoiceCompany(): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();

    return [
        ...$company,
        'invoiceSeries' => posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
            'series_code' => 'FTAPP',
            'document_type' => FiscalDocumentType::Invoice,
        ]),
        'token' => $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'customer' => Customer::factory()->create([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
            'name' => 'Mavinga & Filhos',
            'tax_identification_number' => '5417000000',
            'payment_terms_days' => 30,
        ]),
        'item' => posItem($company),
    ];
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneInvoicePayload(array $company, array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => PHONE_INVOICE_GROSS,
        'issue' => true,
        'password' => 'a-very-good-password',
        ...$overrides,
    ];
}

beforeEach(function () {
    Queue::fake();
});

test('the phone learns what this person can do before they start', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_create', true)
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.reason', null);
});

test('without a series the phone can only keep drafts, and says why', function () {
    $company = phoneInvoiceCompany();
    $company['invoiceSeries']->delete();

    $this->withToken($company['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertJsonPath('data.can_create', true)
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'Não há série de Factura disponível. Pode guardar rascunhos.');
});

test('without two-factor authentication the phone can only keep drafts', function () {
    $company = phoneInvoiceCompany();
    $company['owner']->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();

    $this->withToken($company['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertJsonPath('data.can_create', true)
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_ISSUE');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('the preview works the figures out on the server and writes nothing', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.preview'), [
            'customer_public_id' => $company['customer']->public_id,
            'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        ])
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('data.customer_tax_identification_number', '5417000000')
        ->assertJsonPath('data.due_date', now('Africa/Luanda')->addDays(30)->toDateString())
        ->assertJsonPath('data.lines.0.quantity', '2')
        ->assertJsonPath('data.lines.0.gross_minor', PHONE_INVOICE_GROSS)
        ->assertJsonPath('data.net_total_minor', 20_000)
        ->assertJsonPath('data.tax_total_minor', 2_800)
        ->assertJsonPath('data.gross_total_minor', PHONE_INVOICE_GROSS);

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a price agreed with the customer is the one that counts', function () {
    $company = phoneInvoiceCompany();
    CustomerPrice::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'customer_id' => $company['customer']->id,
        'catalogue_item_id' => $company['item']->id,
        'unit_price_minor' => 5_000,
    ]);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.preview'), [
            'customer_public_id' => $company['customer']->public_id,
            'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        ])
        ->assertJsonPath('data.gross_total_minor', 11_400);
});

test('confirming with the password issues one numbered invoice and queues it for the AGT', function () {
    $company = phoneInvoiceCompany();

    $response = $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company))
        ->assertCreated()
        ->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.gross_total_minor', PHONE_INVOICE_GROSS)
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos');

    $document = FiscalDocument::query()->sole();

    expect($document->document_type)->toBe(FiscalDocumentType::Invoice)
        ->and($document->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($document->customer_id)->toBe($company['customer']->id)
        ->and($document->customer_tax_identification_number)->toBe('5417000000')
        ->and($document->establishment_id)->toBe($company['establishment']->id)
        ->and($document->due_date->toDateString())->toBe(now('Africa/Luanda')->addDays(30)->toDateString())
        ->and($document->issued_by_user_id)->toBe($company['owner']->id)
        ->and($document->printRecord()->exists())->toBeTrue()
        ->and($company['invoiceSeries']->fresh()->next_number)->toBe(2)
        ->and($response->json('data.document_no'))->toBe($document->document_no)
        ->and($response->json('data.share_url'))->toContain('signature=');

    Queue::assertPushed(SubmitAgtDocument::class);
});

test('a wrong password issues nothing and spends no number', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, ['password' => 'not-the-password']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and($company['invoiceSeries']->fresh()->next_number)->toBe(1);

    Queue::assertNotPushed(SubmitAgtDocument::class);
});

test('issuing without a password is refused', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, ['password' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a retry with the same key answers with the invoice already made', function () {
    $company = phoneInvoiceCompany();
    $payload = phoneInvoicePayload($company);

    $first = $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), $payload)->assertCreated();

    $this->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.document_no', $first->json('data.document_no'));

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and(MobileInvoice::query()->count())->toBe(1)
        ->and($company['invoiceSeries']->fresh()->next_number)->toBe(2);

    Queue::assertPushed(SubmitAgtDocument::class, 1);
});

test('a total that no longer matches is refused and nothing is left behind', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, ['expected_total_minor' => 22_799]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_total_minor']);

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and(MobileInvoice::query()->count())->toBe(0)
        ->and($company['invoiceSeries']->fresh()->next_number)->toBe(1);
});

test('the phone cannot set a price', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, [
            'lines' => [[
                'catalogue_item_public_id' => $company['item']->public_id,
                'quantity' => '2',
                'unit_price' => '1.00',
            ]],
        ]))
        ->assertUnprocessable();

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a draft needs no password and spends no number', function () {
    $company = phoneInvoiceCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, ['issue' => false, 'password' => null]))
        ->assertCreated()
        ->assertJsonPath('data.is_issued', false)
        ->assertJsonPath('data.document_no', null)
        ->assertJsonPath('data.share_url', null);

    expect(FiscalDocument::query()->sole()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($company['invoiceSeries']->fresh()->next_number)->toBe(1);

    Queue::assertNotPushed(SubmitAgtDocument::class);
});

test('with no series left the invoice is refused by name and nothing is kept', function () {
    $company = phoneInvoiceCompany();
    $company['invoiceSeries']->delete();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company))
        ->assertConflict()
        ->assertJsonPath('error.code', 'INVOICE_BLOCKED');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a viewer can neither preview nor create', function () {
    $company = phoneInvoiceCompany();
    $company['owner']->workspaceMemberships()->update(['role' => WorkspaceRole::Viewer]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.invoices.setup'))
        ->assertJsonPath('data.can_create', false)
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'INVOICE_NOT_ALLOWED');

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('a customer or an item from another company is not available', function () {
    $company = phoneInvoiceCompany();
    $other = Customer::factory()->create();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company, ['customer_public_id' => $other->public_id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_public_id']);

    expect(FiscalDocument::query()->count())->toBe(0);
});

test('making invoices needs a token', function () {
    $this->getJson(route('mobile.v1.invoices.setup'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.invoices.preview'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.invoices.store'))->assertUnauthorized();
});

test('a minute of using the app does not use up the invoices that may be issued', function () {
    $company = phoneInvoiceCompany();

    foreach (range(1, 15) as $ignored) {
        $this->withToken($company['token'])->getJson(route('mobile.v1.invoices.setup'))->assertOk();
    }

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), phoneInvoicePayload($company))
        ->assertCreated();
});
