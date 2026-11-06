<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\SubmitAgtDocument;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use App\Models\MobileInvoice;
use App\Notifications\PaymentReceived;
use App\PaymentMethod;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../PosFixtures.php';

/**
 * @return array<string, mixed>
 */
function phonePaymentCompany(): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();

    return [
        ...$company,
        'invoiceSeries' => posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
            'series_code' => 'FTAPP',
            'document_type' => FiscalDocumentType::Invoice,
        ]),
        'receiptSeries' => posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
            'series_code' => 'RGAPP',
            'document_type' => FiscalDocumentType::Receipt,
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
 * Issues a 228,00 invoice through the phone.
 *
 * @param  array<string, mixed>  $company
 */
function phonePaymentInvoice(array $company, bool $accepted = true, ?Customer $customer = null): FiscalDocument
{
    $publicId = test()->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => ($customer ?? $company['customer'])->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => 22_800,
        'issue' => true,
        'password' => 'a-very-good-password',
    ])->assertCreated()->json('data.public_id');

    $invoice = FiscalDocument::query()->where('public_id', $publicId)->firstOrFail();

    if ($accepted) {
        recordAuthoritativeAgtAcceptance($invoice);
    }

    return $invoice->refresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phonePaymentPayload(FiscalDocument $invoice, int $amountMinor = 22_800, array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'payment_method' => PaymentMethod::BankTransfer->value,
        'payment_date' => now('Africa/Luanda')->toDateString(),
        'settlements' => [['document_public_id' => $invoice->public_id, 'amount_minor' => $amountMinor]],
        'password' => 'a-very-good-password',
        ...$overrides,
    ];
}

function phonePaymentRoute(string $name, Customer $customer): string
{
    return route("mobile.v1.payments.{$name}", ['customerPublicId' => $customer->public_id]);
}

function phoneReceipts()
{
    return FiscalDocument::query()->where('document_type', FiscalDocumentType::Receipt);
}

beforeEach(function () {
    Queue::fake();
});

test('the phone sees what a customer owes, oldest first, and which invoices can be paid', function () {
    $company = phonePaymentCompany();
    $oldest = phonePaymentInvoice($company);
    $newest = phonePaymentInvoice($company, accepted: false);

    $this->withToken($company['token'])->getJson(phonePaymentRoute('show', $company['customer']))
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('data.today', now('Africa/Luanda')->toDateString())
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.documents.0.public_id', $oldest->public_id)
        ->assertJsonPath('data.documents.0.outstanding_minor', 22_800)
        ->assertJsonPath('data.documents.0.is_settleable', true)
        ->assertJsonPath('data.documents.0.blocked_reason', null)
        ->assertJsonPath('data.documents.1.public_id', $newest->public_id)
        ->assertJsonPath('data.documents.1.is_settleable', false)
        ->assertJsonPath('data.documents.1.blocked_reason', 'Ainda sem validação da AGT.');
});

test('a payment issues a receipt that reaches the AGT and lowers what is owed', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);
    Queue::fake();

    $response = $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 10_000))
        ->assertCreated()
        ->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.gross_total_minor', 10_000)
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('data.replayed', false);

    $receipt = phoneReceipts()->sole();

    expect($response->json('data.document_no'))->toStartWith('RG RGAPP')
        ->and($response->json('data.share_url'))->not->toBeNull()
        ->and($receipt->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($receipt->payment_date->toDateString())->toBe(now('Africa/Luanda')->toDateString())
        ->and($receipt->payment_amount_minor)->toBe(10_000)
        ->and($receipt->customer_id)->toBe($company['customer']->id);

    Queue::assertPushed(SubmitAgtDocument::class);

    $this->get($response->json('data.share_url'))->assertOk();

    $this->withToken($company['token'])->getJson(phonePaymentRoute('show', $company['customer']))
        ->assertJsonPath('data.documents.0.outstanding_minor', 12_800);
});

test('one payment can settle several invoices, each for its own amount', function () {
    $company = phonePaymentCompany();
    $first = phonePaymentInvoice($company);
    $second = phonePaymentInvoice($company);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($first, overrides: [
            'settlements' => [
                ['document_public_id' => $first->public_id, 'amount_minor' => 22_800],
                ['document_public_id' => $second->public_id, 'amount_minor' => 1_000],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.gross_total_minor', 23_800);

    expect(FiscalDocumentSettlement::query()->pluck('amount_minor', 'settled_document_id')->all())
        ->toBe([$first->id => 22_800, $second->id => 1_000]);
});

test('sending the same payment twice makes one receipt', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);
    $payload = phonePaymentPayload($invoice, 10_000);

    $first = $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), $payload)
        ->assertCreated();

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), $payload)
        ->assertOk()
        ->assertJsonPath('data.document_no', $first->json('data.document_no'))
        ->assertJsonPath('data.replayed', true);

    expect(phoneReceipts()->count())->toBe(1);
});

test('a payment needs the password and two-factor authentication', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, overrides: ['password' => 'wrong']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    $company['owner']->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();
    app('auth')->forgetGuards();

    $this->withToken($company['token'])->getJson(phonePaymentRoute('show', $company['customer']))
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_ISSUE');

    expect(phoneReceipts()->count())->toBe(0);
});

test('a role that cannot prepare documents cannot record a payment', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);
    $viewer = posMember($company, WorkspaceRole::Viewer);
    $token = $viewer->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson(phonePaymentRoute('show', $company['customer']))
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($token)
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice))
        ->assertForbidden();

    expect(phoneReceipts()->count())->toBe(0);
});

test('a payment cannot be more than is still owed', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 22_801))
        ->assertUnprocessable();

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 20_000))
        ->assertCreated();

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 2_801))
        ->assertUnprocessable();

    expect(phoneReceipts()->count())->toBe(1);
});

test('a credit note lowers what a payment may collect', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);

    FiscalDocument::factory()->create([
        'workspace_id' => $invoice->workspace_id,
        'legal_entity_id' => $invoice->legal_entity_id,
        'establishment_id' => $invoice->establishment_id,
        'created_by_user_id' => $company['owner']->id,
        'updated_by_user_id' => $company['owner']->id,
        'customer_id' => $company['customer']->id,
        'document_type' => FiscalDocumentType::CreditNote,
        'document_no' => 'NC TESTE/1',
        'status' => FiscalDocumentStatus::Valid,
        'references_document_id' => $invoice->id,
        'gross_total_minor' => 20_000,
        'net_total_minor' => 20_000,
        'tax_payable_minor' => 0,
    ]);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 2_801))
        ->assertUnprocessable();

    expect(phoneReceipts()->count())->toBe(0);
});

test('an invoice of another customer cannot be paid through this one', function () {
    $company = phonePaymentCompany();
    $other = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'tax_identification_number' => '5417000001',
    ]);
    $invoice = phonePaymentInvoice($company, customer: $other);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice))
        ->assertUnprocessable();

    expect(phoneReceipts()->count())->toBe(0);
});

test('an invoice without AGT acceptance is refused, on the record and without spending a number', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company, accepted: false);
    $nextNumber = $company['receiptSeries']->refresh()->next_number;

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice))
        ->assertConflict()
        ->assertJsonPath('error.code', 'PAYMENT_BLOCKED');

    $denial = Activity::query()->where('event', 'receipt-evidence-denied')->sole();

    expect(phoneReceipts()->count())->toBe(0)
        ->and(MobileInvoice::query()->count())->toBe(1)
        ->and($company['receiptSeries']->refresh()->next_number)->toBe($nextNumber)
        ->and($denial->getProperty('settled_document_public_ids'))->toBe([$invoice->public_id])
        ->and($denial->causer_id)->toBe($company['owner']->id);
});

test('without a receipt series the phone says so and nothing is issued', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);
    $company['receiptSeries']->delete();

    $this->withToken($company['token'])->getJson(phonePaymentRoute('show', $company['customer']))
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'Não há série de Recibo disponível. Peça uma série à AGT em facturac.ao.');

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice))
        ->assertConflict();

    expect(phoneReceipts()->count())->toBe(0);
});

test('a payment cannot be dated in the future', function () {
    $company = phonePaymentCompany();
    $invoice = phonePaymentInvoice($company);

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, overrides: ['payment_date' => now('Africa/Luanda')->addDay()->toDateString()]))
        ->assertUnprocessable();

    expect(phoneReceipts()->count())->toBe(0);
});

test('another company\'s customer stays out of reach', function () {
    $company = phonePaymentCompany();
    $stranger = Customer::factory()->create();

    $this->withToken($company['token'])->getJson(phonePaymentRoute('show', $stranger))->assertNotFound();
});

test('a payment tells the rest of the company, and not whoever recorded it', function () {
    $company = phonePaymentCompany();
    $colleague = posMember($company, WorkspaceRole::Viewer);
    $invoice = phonePaymentInvoice($company);
    Notification::fake();

    $this->withToken($company['token'])
        ->postJson(phonePaymentRoute('store', $company['customer']), phonePaymentPayload($invoice, 10_000))
        ->assertCreated();

    $receipt = phoneReceipts()->sole();

    Notification::assertSentTo(
        $colleague,
        PaymentReceived::class,
        fn (PaymentReceived $notification): bool => $notification->documentPublicId === $receipt->public_id
            && $notification->body() === "Mavinga & Filhos pagou 100,00 Kz. Recibo {$receipt->document_no} emitido.",
    );
    Notification::assertNotSentTo($company['owner'], PaymentReceived::class);
});

test('issuing an invoice tells nobody a payment came in', function () {
    $company = phonePaymentCompany();
    $colleague = posMember($company, WorkspaceRole::Viewer);
    Notification::fake();

    phonePaymentInvoice($company, accepted: false);

    Notification::assertNotSentTo($colleague, PaymentReceived::class);
});
