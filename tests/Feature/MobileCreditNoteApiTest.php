<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\SubmitAgtDocument;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

/** 2 × 100,00 at 14 % IVA. */
const CREDITED_INVOICE_GROSS = 22_800;

/**
 * A company with one phone invoice for 2 × 100,00 at 14 % that the AGT has validated.
 *
 * @return array<string, mixed>
 */
function creditNoteCompany(bool $validated = true): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();
    $company['token'] = $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    $company['customer'] = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Mavinga & Filhos',
        'tax_identification_number' => '5417000000',
    ]);
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'FTAPP',
        'document_type' => FiscalDocumentType::Invoice,
    ]);
    $company['noteSeries'] = posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'NCAPP',
        'document_type' => FiscalDocumentType::CreditNote,
    ]);

    test()->withToken($company['token'])
        ->postJson(route('mobile.v1.invoices.store'), [
            'client_key' => (string) Str::uuid(),
            'customer_public_id' => $company['customer']->public_id,
            'lines' => [['catalogue_item_public_id' => posItem($company)->public_id, 'quantity' => '2']],
            'expected_total_minor' => CREDITED_INVOICE_GROSS,
            'issue' => true,
            'password' => 'a-very-good-password',
        ])
        ->assertCreated();
    app('auth')->forgetGuards();

    $company['invoice'] = FiscalDocument::query()->where('document_type', FiscalDocumentType::Invoice)->sole();

    if ($validated) {
        recordAuthoritativeAgtAcceptance($company['invoice']);
    }

    return $company;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function creditNotePayload(string $quantity = '1', array $overrides = []): array
{
    return [
        'client_key' => (string) Str::uuid(),
        'reason' => 'Devolução de mercadoria',
        'lines' => [['line_number' => 1, 'quantity' => $quantity]],
        'expected_total_minor' => (int) ($quantity * CREDITED_INVOICE_GROSS / 2),
        'password' => 'a-very-good-password',
        ...$overrides,
    ];
}

beforeEach(function () {
    Queue::fake();
});

test('the phone sees what is left of each line and may issue', function () {
    $company = creditNoteCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_credit', true);

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertOk()
        ->assertJsonPath('data.document_no', $company['invoice']->document_no)
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.lines.0.line_number', 1)
        ->assertJsonPath('data.lines.0.quantity', '2')
        ->assertJsonPath('data.lines.0.credited_quantity', '0')
        ->assertJsonPath('data.lines.0.creditable_quantity', '2')
        ->assertJsonPath('data.lines.0.unit_gross_minor', CREDITED_INVOICE_GROSS / 2);
});

test('an invoice the AGT has not validated is not offered for correction', function () {
    $company = creditNoteCompany(validated: false);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_credit', false);

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.');

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload())
        ->assertConflict()
        ->assertJsonPath('error.code', 'CREDIT_NOTE_BLOCKED');

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0);
});

test('the preview works the note out from the invoice and writes nothing', function () {
    $company = creditNoteCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.preview', $company['invoice']->public_id), [
            'lines' => [['line_number' => 1, 'quantity' => '1']],
        ])
        ->assertOk()
        ->assertJsonPath('data.lines.0.quantity', '1')
        ->assertJsonPath('data.net_total_minor', 10_000)
        ->assertJsonPath('data.tax_total_minor', 1_400)
        ->assertJsonPath('data.gross_total_minor', CREDITED_INVOICE_GROSS / 2);

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0);
});

test('issuing makes a credit note that names the invoice, its line and the reason', function () {
    $company = creditNoteCompany();

    $response = $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload())
        ->assertCreated()
        ->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.replayed', false);

    $note = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();
    $line = $note->lines()->sole();

    expect($note->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($note->references_document_id)->toBe($company['invoice']->id)
        ->and($note->references_document_no)->toBe($company['invoice']->document_no)
        ->and($note->adjustment_reason)->toBe('Devolução de mercadoria')
        ->and($note->customer_id)->toBe($company['customer']->id)
        ->and($note->gross_total_minor)->toBe(CREDITED_INVOICE_GROSS / 2)
        ->and($note->issued_by_user_id)->toBe($company['owner']->id)
        ->and($line->reference_line_number)->toBe(1)
        ->and($line->product_code)->toBe($company['invoice']->lines()->sole()->product_code)
        ->and($company['noteSeries']->fresh()->next_number)->toBe(2)
        ->and($response->json('data.document_no'))->toBe($note->document_no);

    Queue::assertPushed(SubmitAgtDocument::class, 2);

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertJsonPath('data.lines.0.credited_quantity', '1')
        ->assertJsonPath('data.lines.0.creditable_quantity', '1');
});

test('no line can be credited past what is left of it', function () {
    $company = creditNoteCompany();
    $url = route('mobile.v1.credit-notes.store', $company['invoice']->public_id);

    $this->withToken($company['token'])->postJson($url, creditNotePayload('3'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.quantity']);

    $this->withToken($company['token'])->postJson($url, creditNotePayload('2'))->assertCreated();

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'Este documento já foi creditado por inteiro.');

    $this->withToken($company['token'])->postJson($url, creditNotePayload('1'))
        ->assertConflict()
        ->assertJsonPath('error.message', 'Este documento já foi creditado por inteiro.');

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(1);
});

test('a line the invoice does not have is refused', function () {
    $company = creditNoteCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload(overrides: [
            'lines' => [['line_number' => 7, 'quantity' => '1']],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.line_number']);
});

test('a retry with the same key answers with the note already made', function () {
    $company = creditNoteCompany();
    $payload = creditNotePayload();
    $url = route('mobile.v1.credit-notes.store', $company['invoice']->public_id);

    $first = $this->withToken($company['token'])->postJson($url, $payload)->assertCreated();

    $this->withToken($company['token'])->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('data.public_id', $first->json('data.public_id'))
        ->assertJsonPath('data.replayed', true);

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(1);
});

test('a total that changed since the preview issues nothing', function () {
    $company = creditNoteCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload(overrides: ['expected_total_minor' => 1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_total_minor']);

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0)
        ->and($company['noteSeries']->fresh()->next_number)->toBe(1);
});

test('issuing a note needs the password and two-factor authentication', function () {
    $company = creditNoteCompany();
    $url = route('mobile.v1.credit-notes.store', $company['invoice']->public_id);

    $this->withToken($company['token'])->postJson($url, creditNotePayload(overrides: ['password' => 'not-the-password']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    $company['owner']->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();
    app('auth')->forgetGuards();

    $this->withToken($company['token'])->postJson($url, creditNotePayload())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_ISSUE');

    expect(FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0);
});

test('the reason is required and fits what the AGT accepts', function (?string $reason) {
    $company = creditNoteCompany();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload(overrides: ['reason' => $reason]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
})->with([
    'missing' => null,
    'too short' => 'Erro',
    'too long' => str_repeat('a', 61),
]);

test('a viewer may not issue a note', function () {
    $company = creditNoteCompany();
    WorkspaceMembership::query()->where('user_id', $company['owner']->id)->update(['role' => WorkspaceRole::Viewer]);
    app('auth')->forgetGuards();

    $this->withToken($company['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CREDIT_NOTE_NOT_ALLOWED');
});

test('another company\'s invoice is not found', function () {
    $company = creditNoteCompany();
    $other = posCompany();
    $other['token'] = $other['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($other['token'])->getJson(route('mobile.v1.credit-notes.show', $company['invoice']->public_id))
        ->assertNotFound();

    $this->withToken($other['token'])
        ->postJson(route('mobile.v1.credit-notes.store', $company['invoice']->public_id), creditNotePayload())
        ->assertNotFound();
});
