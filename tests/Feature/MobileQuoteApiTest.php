<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\SubmitAgtDocument;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\FiscalDocument;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteWasAccepted;
use App\QuoteStatus;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

/**
 * 2 × 100,00 at 14 % IVA: 200,00 net, 28,00 tax, 228,00 to pay.
 */
const PHONE_QUOTE_GROSS = 22_800;

/**
 * @return array<string, mixed>
 */
function phoneQuoteCompany(): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();

    return [
        ...$company,
        'invoiceSeries' => posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
            'series_code' => 'FTAPP',
            'document_type' => FiscalDocumentType::Invoice,
        ]),
        'token' => quoteToken($company['owner']),
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

function quoteToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneQuotePayload(array $company, array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'valid_for_days' => 30,
        'expected_total_minor' => PHONE_QUOTE_GROSS,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $company
 */
function phoneQuote($test, array $company, QuoteStatus $status = QuoteStatus::Draft): Quote
{
    $test->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company))->assertCreated();
    $quote = Quote::query()->latest('id')->firstOrFail();

    if ($status !== QuoteStatus::Draft) {
        $quote->forceFill(['status' => $status])->save();
    }

    return $quote;
}

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

it('previews a quote from ids and quantities, with the server\'s prices and taxes', function (): void {
    $company = phoneQuoteCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.preview'), [
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
    ])
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('data.net_total_minor', 20_000)
        ->assertJsonPath('data.tax_total_minor', 2_800)
        ->assertJsonPath('data.gross_total_minor', PHONE_QUOTE_GROSS)
        ->assertJsonPath('data.due_date', null);

    expect(Quote::query()->count())->toBe(0);
});

it('makes a draft quote through the web\'s action, and a repeat comes back as the same one', function (): void {
    $company = phoneQuoteCompany();
    $payload = phoneQuotePayload($company);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.can_send', true)
        ->assertJsonPath('data.can_convert', false)
        ->assertJsonPath('data.valid_until', now('Africa/Luanda')->addDays(30)->toDateString())
        ->assertJsonPath('data.summary.lines.0.quantity', '2')
        ->assertJsonPath('data.summary.gross_total_minor', PHONE_QUOTE_GROSS);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), $payload)
        ->assertOk();

    $quote = Quote::query()->sole();

    expect($quote->reference)->toStartWith('ORC ')
        ->and($quote->lines()->sole()->product_code)->toBe($company['item']->code);
});

it('ignores any price the phone sends, and uses the one agreed with this customer', function (): void {
    $company = phoneQuoteCompany();
    CustomerPrice::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'customer_id' => $company['customer']->id,
        'catalogue_item_id' => $company['item']->id,
        'unit_price_minor' => 5_000,
    ]);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company, [
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2', 'unit_price' => '1.00']],
    ]))->assertUnprocessable();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company, [
        'expected_total_minor' => 11_400,
    ]))->assertCreated()->assertJsonPath('data.gross_total_minor', 11_400);
});

it('refuses a total that moved since the screen showed it, and keeps no quote', function (): void {
    $company = phoneQuoteCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company, [
        'expected_total_minor' => 1,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'O total mudou desde que o orçamento foi apresentado. Reveja-o antes de continuar.');

    expect(Quote::query()->count())->toBe(0);
});

it('holds the quote to the web form\'s ceilings, and a sum too large is a message, not a crash', function (): void {
    $company = phoneQuoteCompany();
    $dear = posItem($company, ['unit_price_minor' => 100_000_000_001]);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.preview'), [
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '1000001']],
    ])->assertJsonValidationErrors('lines.0.quantity');

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.preview'), [
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $dear->public_id, 'quantity' => '1']],
    ])->assertJsonValidationErrors('lines');

    $large = posItem($company, ['unit_price_minor' => 100_000_000_000]);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.preview'), [
        'customer_public_id' => $company['customer']->public_id,
        'lines' => array_fill(0, 200, ['catalogue_item_public_id' => $large->public_id, 'quantity' => '1000000']),
    ])->assertUnprocessable();
});

it('lists open quotes by default, searches them, and shows a lapsed one as expired', function (): void {
    $company = phoneQuoteCompany();
    $open = phoneQuote($this, $company);
    $rejected = phoneQuote($this, $company, QuoteStatus::Rejected);
    $lapsed = phoneQuote($this, $company);
    $lapsed->forceFill(['valid_until' => now()->subDays(2)])->save();

    $this->withToken($company['token'])->getJson(route('mobile.v1.quotes.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.public_id', $lapsed->public_id)
        ->assertJsonPath('data.0.status', 'expired')
        ->assertJsonPath('data.0.tone', 'critical')
        ->assertJsonPath('data.1.public_id', $open->public_id);

    $this->withToken($company['token'])->getJson(route('mobile.v1.quotes.index', ['status' => 'all']))
        ->assertJsonCount(3, 'data');

    $this->withToken($company['token'])->getJson(route('mobile.v1.quotes.index', ['status' => 'all', 'q' => $rejected->reference]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $rejected->public_id);
});

it('keeps another company\'s quote out of reach', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company);
    $other = phoneQuoteCompany();
    auth()->forgetGuards();

    $this->withToken($other['token'])->getJson(route('mobile.v1.quotes.show', $quote->public_id))->assertNotFound();
    $this->withToken($other['token'])->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'sent'])->assertNotFound();
    $this->withToken($other['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))->assertNotFound();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft);
});

it('lets a viewer read a quote but change nothing, and says why', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company);
    $token = quoteToken(posMember($company, WorkspaceRole::Viewer));
    auth()->forgetGuards();

    $this->withToken($token)->getJson(route('mobile.v1.quotes.show', $quote->public_id))
        ->assertOk()
        ->assertJsonPath('data.can_send', false);

    $this->withToken($token)->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'sent'])
        ->assertForbidden()
        ->assertJsonPath('error.message', 'A sua função nesta empresa não permite gerir orçamentos.');

    $this->withToken($token)->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company))->assertForbidden();
    $this->withToken($token)->postJson(route('mobile.v1.quotes.convert', $quote->public_id))->assertForbidden();
});

it('marks a quote sent without delivering anything, and a repeat is harmless', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company);

    $this->withToken($company['token'])->getJson(route('mobile.v1.quotes.show', $quote->public_id))
        ->assertJsonPath('data.can_share_pdf', true)
        ->assertJsonMissingPath('data.share_text');

    foreach ([1, 2] as $attempt) {
        $this->withToken($company['token'])->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'sent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.can_decide', true)
            ->assertJsonPath('data.can_convert', true);
    }

    expect($quote->fresh()->sent_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('records a decision, telling the workspace about a yes, and refuses a skipped step', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company);

    $this->withToken($company['token'])->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'accepted'])
        ->assertConflict()
        ->assertJsonPath('error.message', 'Esta mudança de estado não é permitida para o orçamento.');

    $this->withToken($company['token'])->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'sent'])->assertOk();
    $this->withToken($company['token'])->putJson(route('mobile.v1.quotes.status', $quote->public_id), ['status' => 'accepted'])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.tone', 'positive');

    Notification::assertSentTimes(QuoteWasAccepted::class, 1);
});

it('turns a sent quote into a draft invoice once, using no number', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company, QuoteStatus::Sent);

    foreach ([1, 2] as $attempt) {
        $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))
            ->assertOk()
            ->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.can_convert', false)
            ->assertJsonPath('data.invoice.receipt.is_issued', false)
            ->assertJsonPath('data.invoice.receipt.document_no', null)
            ->assertJsonPath('data.invoice.can_issue', true)
            ->assertJsonPath('data.invoice.summary.gross_total_minor', PHONE_QUOTE_GROSS)
            ->assertJsonPath('data.invoice.summary.lines.0.quantity', '2')
            ->assertJsonPath('data.invoice.summary.lines.0.tax_percentage', '14');
    }

    expect(FiscalDocument::query()->sole()->status)->toBe(FiscalDocumentStatus::Draft);
});

it('refuses to convert a draft, a lapsed quote, or one with no NIF', function (): void {
    $company = phoneQuoteCompany();
    $draft = phoneQuote($this, $company);

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $draft->public_id))
        ->assertConflict()
        ->assertJsonPath('error.message', 'Só um orçamento enviado ou aceite pode passar a factura.');

    $lapsed = phoneQuote($this, $company, QuoteStatus::Sent);
    $lapsed->forceFill(['status' => QuoteStatus::Draft])->save();
    $lapsed->forceFill(['valid_until' => now()->subDays(2)])->save();
    $lapsed->forceFill(['status' => QuoteStatus::Sent])->save();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $lapsed->public_id))
        ->assertConflict()
        ->assertJsonPath('error.code', 'QUOTE_LAPSED');

    $company['customer']->forceFill(['tax_identification_number' => '123'])->save();
    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company))->assertCreated();
    $noNif = Quote::query()->latest('id')->firstOrFail();
    $noNif->forceFill(['status' => QuoteStatus::Sent])->save();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $noNif->public_id))
        ->assertConflict()
        ->assertJsonPath('error.message', 'Indique o NIF do cliente no orçamento antes de o passar a factura.');

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('issues the invoice only with the password, once, through the normal pipeline', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company, QuoteStatus::Sent);
    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))->assertOk();
    $issue = route('mobile.v1.quotes.issue', $quote->public_id);
    $document = FiscalDocument::query()->sole();
    $body = ['password' => 'a-very-good-password', 'revision' => $document->revision, 'expected_total_minor' => PHONE_QUOTE_GROSS];

    $this->withToken($company['token'])->postJson($issue, [...$body, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');
    expect($document->fresh()->document_no)->toBeNull();

    $this->withToken($company['token'])->postJson($issue, $body)
        ->assertCreated()
        ->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.gross_total_minor', PHONE_QUOTE_GROSS);

    $number = $document->fresh()->document_no;
    expect($number)->toStartWith('FT');
    Queue::assertPushed(SubmitAgtDocument::class, 1);

    $this->withToken($company['token'])->postJson($issue, $body)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.document_no', $number);

    Queue::assertPushed(SubmitAgtDocument::class, 1);
});

it('gives writing its own allowance, so a busy minute of reading cannot block it', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company, QuoteStatus::Sent);

    foreach (range(1, 15) as $read) {
        $this->withToken($company['token'])->getJson(route('mobile.v1.quotes.index'))->assertOk();
    }

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))->assertOk();
});

it('refuses to issue a draft that changed on the web since it was reviewed', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company, QuoteStatus::Sent);
    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))->assertOk();
    $document = FiscalDocument::query()->sole();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.issue', $quote->public_id), [
        'password' => 'a-very-good-password',
        'revision' => $document->revision + 1,
        'expected_total_minor' => PHONE_QUOTE_GROSS,
    ])->assertConflict()->assertJsonPath('error.code', 'INVOICE_CHANGED');

    expect($document->fresh()->document_no)->toBeNull();
});

it('will not issue without two-factor authentication, and says so on the quote', function (): void {
    $company = phoneQuoteCompany();
    $quote = phoneQuote($this, $company, QuoteStatus::Sent);
    $company['owner']->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();
    auth()->forgetGuards();

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.convert', $quote->public_id))
        ->assertOk()
        ->assertJsonPath('data.invoice.can_issue', false)
        ->assertJsonPath('data.invoice.reason', 'Para emitir, active a autenticação em dois passos em facturac.ao.');

    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.issue', $quote->public_id), [
        'password' => 'a-very-good-password',
        'revision' => 1,
        'expected_total_minor' => PHONE_QUOTE_GROSS,
    ])->assertForbidden()->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_ISSUE');
});

test('fractional quote preview and draft retain all four decimal places', function (): void {
    $company = phoneQuoteCompany();
    $lines = [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '1.2345']];
    $this->withToken($company['token'])->postJson(route('mobile.v1.quotes.preview'), [
        'customer_public_id' => $company['customer']->public_id, 'lines' => $lines,
    ])->assertOk()->assertJsonPath('data.lines.0.quantity', '1.2345')->assertJsonPath('data.gross_total_minor', 14_074);
    $this->postJson(route('mobile.v1.quotes.store'), phoneQuotePayload($company, [
        'lines' => $lines, 'expected_total_minor' => 14_074,
    ]))->assertCreated();
    $quote = Quote::query()->sole();
    expect($quote->lines()->sole()->quantity_units)->toBe(12_345);
    expect($quote->lines()->sole()->quantity_scale)->toBe(4);
    expect($quote->gross_total_minor)->toBe(14_074);
    expect(FiscalDocument::count())->toBe(0);
    Queue::assertNothingPushed();
});
