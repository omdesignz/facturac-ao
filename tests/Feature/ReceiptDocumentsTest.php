<?php

use App\Fiscal\Documents\V1_2\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use App\Models\LegalEntity;
use App\Models\User;
use App\PaymentMethod;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, legal_entity: LegalEntity, establishment: Establishment, invoice: FiscalDocument}
 */
function receiptCompany(int $invoiceGrossMinor = 100000): array
{
    $user = User::factory()->withWorkspace('VAP Recibos')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $invoice = FiscalDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
        'document_type' => FiscalDocumentType::Invoice,
        'document_no' => 'FT TESTE/1',
        'gross_total_minor' => $invoiceGrossMinor,
    ]);

    return compact('user', 'establishment', 'invoice') + ['legal_entity' => $legalEntity];
}

/**
 * @return array<string, mixed>
 */
function receiptPayload(array $company, array $overrides = []): array
{
    return array_replace([
        'document_type' => FiscalDocumentType::IssuedReceipt->value,
        'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => null,
        'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => null,
        'customer' => [
            'name' => 'Cliente Pagador, Lda.',
            'tax_identification_number' => '5411111111',
            'country_code' => 'AO',
            'address_line' => 'Luanda',
        ],
        'notes' => null,
        'payment_method' => PaymentMethod::BankTransfer->value,
        'payment_date' => now('Africa/Luanda')->toDateString(),
        'settlements' => [[
            'document_public_id' => $company['invoice']->public_id,
            'amount' => '1000.00',
        ]],
        'lines' => [],
    ], $overrides);
}

test('receipts are issuable and carry the right shape', function () {
    $values = array_map(
        fn (FiscalDocumentType $type): string => $type->value,
        FiscalDocumentType::issuable(),
    );

    expect($values)->toContain('FR')
        ->and($values)->toContain('RC')
        // FR invoices and is paid in one document, so it still carries lines.
        ->and(FiscalDocumentType::InvoiceReceipt->requiresLines())->toBeTrue()
        ->and(FiscalDocumentType::InvoiceReceipt->settlesOtherDocuments())->toBeFalse()
        // RC only settles earlier invoices.
        ->and(FiscalDocumentType::IssuedReceipt->requiresLines())->toBeFalse()
        ->and(FiscalDocumentType::IssuedReceipt->settlesOtherDocuments())->toBeTrue();
});

test('a receipt settles an invoice and takes its total from the settlement', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $receipt = FiscalDocument::query()
        ->where('document_type', FiscalDocumentType::IssuedReceipt)
        ->firstOrFail();

    expect($receipt->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($receipt->gross_total_minor)->toBe(100000)
        ->and($receipt->payment_amount_minor)->toBe(100000)
        ->and($receipt->lines()->count())->toBe(0)
        ->and($receipt->settlements()->count())->toBe(1)
        ->and($receipt->settlements()->first()->settled_document_no)->toBe('FT TESTE/1');
});

test('a receipt cannot collect more than the invoice still owes', function () {
    $company = receiptCompany(100000);

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'settlements' => [[
                'document_public_id' => $company['invoice']->public_id,
                'amount' => '1500.00',
            ]],
        ]))
        ->assertSessionHasErrors('settlements.0.amount');
});

test('the outstanding balance accounts for earlier receipts', function () {
    $company = receiptCompany(100000);

    // An earlier receipt already took half.
    $earlier = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'document_no' => 'RC TESTE/1',
    ]);
    FiscalDocumentSettlement::query()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'fiscal_document_id' => $earlier->id,
        'settled_document_id' => $company['invoice']->id,
        'settled_document_no' => 'FT TESTE/1',
        'amount_minor' => 50000,
    ]);

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'settlements' => [[
                'document_public_id' => $company['invoice']->public_id,
                'amount' => '600.00',
            ]],
        ]))
        ->assertSessionHasErrors('settlements.0.amount');

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'settlements' => [[
                'document_public_id' => $company['invoice']->public_id,
                'amount' => '500.00',
            ]],
        ]))
        ->assertSessionHasNoErrors();
});

test('a receipt needs a payment method and date', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'payment_method' => null,
            'payment_date' => null,
        ]))
        ->assertSessionHasErrors(['payment_method', 'payment_date']);
});

test('a settlement receipt needs at least one invoice', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, ['settlements' => []]))
        ->assertSessionHasErrors('settlements');
});

test('a receipt cannot settle an invoice from another company', function () {
    $company = receiptCompany();
    $other = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'settlements' => [[
                'document_public_id' => $other['invoice']->public_id,
                'amount' => '100.00',
            ]],
        ]))
        ->assertSessionHasErrors('settlements.0.document_public_id');
});

test('an FR carries lines and its own payment', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'document_type' => FiscalDocumentType::InvoiceReceipt->value,
            'settlements' => [],
            'lines' => [[
                'operation_type' => 'TB',
                'product_code' => 'ART-1',
                'product_description' => 'Venda a pronto pagamento',
                'quantity' => '1',
                'unit_of_measure' => 'un',
                'unit_price' => '1000.00',
                'discount_percentage' => '0',
                'tax' => [
                    'type' => 'IVA',
                    'code' => 'NOR',
                    'percentage' => '14',
                    'exemption_code' => null,
                ],
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $receipt = FiscalDocument::query()
        ->where('document_type', FiscalDocumentType::InvoiceReceipt)
        ->firstOrFail();

    expect($receipt->lines()->count())->toBe(1)
        ->and($receipt->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($receipt->gross_total_minor)->toBe(114000);
});

test('an ordinary invoice still needs no payment details', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), receiptPayload($company, [
            'document_type' => FiscalDocumentType::Invoice->value,
            'payment_method' => null,
            'payment_date' => null,
            'settlements' => [],
            'lines' => [[
                'operation_type' => 'TB',
                'product_code' => 'ART-1',
                'product_description' => 'Venda a crédito',
                'quantity' => '1',
                'unit_of_measure' => 'un',
                'unit_price' => '1000.00',
                'discount_percentage' => '0',
                'tax' => [
                    'type' => 'IVA',
                    'code' => 'NOR',
                    'percentage' => '14',
                    'exemption_code' => null,
                ],
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('the AGT payload carries payment details and settled documents', function () {
    $company = receiptCompany();

    $receipt = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'document_no' => 'RC TESTE/9',
        'payment_method' => PaymentMethod::Cash,
        'payment_amount_minor' => 100000,
        'payment_date' => now('Africa/Luanda')->toDateString(),
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ]);
    FiscalDocumentSettlement::query()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'fiscal_document_id' => $receipt->id,
        'settled_document_id' => $company['invoice']->id,
        'settled_document_no' => 'FT TESTE/1',
        'amount_minor' => 100000,
    ]);

    $payload = app(FiscalDocumentPayloadBuilder::class)->document($receipt->fresh());

    expect($payload['paymentMethod'])->toBe('NU')
        ->and($payload['settledDocuments'])->toHaveCount(1)
        ->and($payload['settledDocuments'][0]['documentNo'])->toBe('FT TESTE/1');
});

test('a receipt without payment details never reaches the AGT', function () {
    $company = receiptCompany();

    $receipt = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'document_no' => 'RC TESTE/8',
        'payment_method' => null,
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ]);

    expect(fn () => app(FiscalDocumentPayloadBuilder::class)->document($receipt->fresh()))
        ->toThrow(DomainException::class);
});

test('the create screen opens as a receipt and offers payment methods', function () {
    $company = receiptCompany();

    $this->actingAs($company['user'])
        ->get(route('invoices.create', ['type' => 'RC']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.document_type', 'RC')
            ->has('paymentMethods', 10)
            ->where('adjustableDocuments.0.outstanding_minor', 100000)
        );
});
