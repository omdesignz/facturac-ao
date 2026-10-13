<?php

use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, legal_entity: LegalEntity, establishment: Establishment, invoice: FiscalDocument}
 */
function adjustmentCompany(): array
{
    $user = User::factory()->withWorkspace('VAP Notas')->create();
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
    ]);

    foreach ([1, 2, 3] as $lineNumber) {
        FiscalDocumentLine::factory()->create([
            'fiscal_document_id' => $invoice->id,
            'line_number' => $lineNumber,
            'product_code' => 'SAME-PRODUCT',
        ]);
    }
    FiscalDocument::withoutEvents(fn () => $invoice->forceFill([
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ])->save());

    return compact('user', 'establishment', 'invoice') + ['legal_entity' => $legalEntity];
}

/**
 * @return array<string, mixed>
 */
function adjustmentPayload(array $company, array $overrides = []): array
{
    return array_replace([
        'document_type' => FiscalDocumentType::CreditNote->value,
        'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => null,
        'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => null,
        'customer' => [
            'name' => 'Cliente Corrigido, Lda.',
            'tax_identification_number' => '5411111111',
            'country_code' => 'AO',
            'address_line' => 'Luanda',
        ],
        'notes' => null,
        'references_document_public_id' => $company['invoice']->public_id,
        'adjustment_reason' => 'Devolução parcial da mercadoria',
        'lines' => [[
            'reference_line_number' => 3,
            'operation_type' => 'TB',
            'product_code' => 'ART-1',
            'product_description' => 'Artigo devolvido',
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
    ], $overrides);
}

test('credit and debit notes are issuable document types', function () {
    $values = array_map(
        fn (FiscalDocumentType $type): string => $type->value,
        FiscalDocumentType::issuable(),
    );

    expect($values)->toContain('NC')
        ->and($values)->toContain('ND')
        ->and(FiscalDocumentType::CreditNote->isAdjustment())->toBeTrue()
        ->and(FiscalDocumentType::Invoice->isAdjustment())->toBeFalse();
});

test('a credit note stores the document it corrects and the reason', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company))
        ->assertRedirect();

    $note = FiscalDocument::query()
        ->where('document_type', FiscalDocumentType::CreditNote)
        ->firstOrFail();

    expect($note->references_document_id)->toBe($company['invoice']->id)
        ->and($note->references_document_no)->toBe('FT TESTE/1')
        ->and($note->adjustment_reason)->toBe('Devolução parcial da mercadoria')
        ->and($note->status)->toBe(FiscalDocumentStatus::Draft);
});

test('an adjustment is rejected without the corrected document', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company, [
            'references_document_public_id' => null,
        ]))
        ->assertSessionHasErrors('references_document_public_id');
});

test('an adjustment is rejected without a reason', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company, [
            'adjustment_reason' => null,
        ]))
        ->assertSessionHasErrors('adjustment_reason');
});

test('an ordinary invoice still needs neither reference nor reason', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company, [
            'document_type' => FiscalDocumentType::Invoice->value,
            'references_document_public_id' => null,
            'adjustment_reason' => null,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('a note cannot reference a document from another company', function () {
    $company = adjustmentCompany();
    $other = adjustmentCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company, [
            'references_document_public_id' => $other['invoice']->public_id,
        ]))
        ->assertSessionHasErrors('references_document_public_id');
});

test('a note cannot reference a draft that was never issued', function () {
    $company = adjustmentCompany();

    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_no' => null,
    ]);

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), adjustmentPayload($company, [
            'references_document_public_id' => $draft->public_id,
        ]))
        ->assertSessionHasErrors('references_document_public_id');
});

test('the create screen can be opened directly as a credit note', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->get(route('invoices.create', ['type' => 'NC']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.document_type', 'NC')
            ->has('documentTypes', count(FiscalDocumentType::issuable()))
            ->has('adjustableDocuments', 1)
            ->where('adjustableDocuments.0.document_no', 'FT TESTE/1')
        );
});

test('an unknown or non-issuable type falls back to an invoice', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])
        ->get(route('invoices.create', ['type' => 'ZZ']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('document.document_type', 'FT'));
});

test('the AGT payload carries the corrected document and reason', function () {
    $company = adjustmentCompany();

    $note = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::CreditNote,
        'document_no' => 'NC TESTE/1',
        'references_document_id' => $company['invoice']->id,
        'references_document_no' => 'FT TESTE/1',
        'adjustment_reason' => 'Devolução parcial',
    ]);
    FiscalDocumentLine::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'fiscal_document_id' => $note->id,
        'reference_line_number' => 3,
    ]);
    FiscalDocument::withoutEvents(fn () => $note->forceFill([
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ])->save());

    $payload = app(FiscalDocumentPayloadBuilder::class)->document($note->fresh());

    expect($payload['lines'][0]['referenceInfo'])->toBe([
        'reference' => 'FT TESTE/1',
        'reason' => 'Devolução parcial',
        'referenceItemLineNo' => 3,
    ])
        ->and($payload['lines'][0])->toHaveKey('debitAmount')
        ->and($payload['lines'][0])->not->toHaveKey('creditAmount')
        ->and($payload['documentType'])->toBe('NC');
});

test('an adjustment missing its reference never reaches the AGT payload', function () {
    $company = adjustmentCompany();

    $note = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::CreditNote,
        'document_no' => 'NC TESTE/2',
        'references_document_no' => null,
        'adjustment_reason' => null,
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ]);

    expect(fn () => app(FiscalDocumentPayloadBuilder::class)->document($note->fresh()))
        ->toThrow(DomainException::class);
});

test('an invoice payload is unchanged by the adjustment fields', function () {
    $company = adjustmentCompany();

    $invoice = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'document_no' => 'FT TESTE/9',
        'status' => FiscalDocumentStatus::Issued,
        'system_entry_at' => now(),
    ]);

    $payload = app(FiscalDocumentPayloadBuilder::class)->document($invoice->fresh());

    expect($payload)->not->toHaveKey('referencingDocumentNo')
        ->and($payload)->not->toHaveKey('adjustmentReason')
        ->and($payload['lines'])->toBe([]);
});

test('NC and ND retain original line numbers after reordering and reloading', function (FiscalDocumentType $type) {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company, ['document_type' => $type->value]);
    $payload['lines'][] = array_replace($payload['lines'][0], ['reference_line_number' => 1]);
    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasNoErrors();
    $note = FiscalDocument::where('document_type', $type)->firstOrFail();
    expect($note->lines->pluck('reference_line_number')->all())->toBe([3, 1]);
    $this->get(route('invoices.edit', $note))->assertInertia(fn (Assert $page) => $page
        ->where('document.lines.0.reference_line_number', '3')
        ->where('document.lines.1.reference_line_number', '1')
        ->where('adjustableDocuments.0.lines.2.line_number', 3));
    FiscalDocument::withoutEvents(fn () => $note->forceFill([
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => $type->value.' TEST/2',
        'system_entry_at' => now(),
    ])->save());
    $json = json_decode(app(CanonicalJson::class)->encode(
        app(FiscalDocumentPayloadBuilder::class)->document($note->fresh()),
    ), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($json['lines'], 'lineNumber'))->toBe([1, 2])
        ->and(array_column(array_column($json['lines'], 'referenceInfo'), 'referenceItemLineNo'))->toBe([3, 1]);
})->with([FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote]);

test('adjustment line references must identify an existing original line', function (FiscalDocumentType $type, mixed $reference) {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company, ['document_type' => $type->value]);
    $payload['lines'][0]['reference_line_number'] = $reference;
    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors('lines.0.reference_line_number');
    expect(FiscalDocument::where('document_type', $type)->count())->toBe(0);
})->with([FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote])
    ->with([null, 0, -1, 4, '1.5', 'unknown']);

test('legacy or tampered adjustment references fail before payload signing', function (FiscalDocumentType $type, string $fault) {
    $company = adjustmentCompany();
    $this->actingAs($company['user'])->post(route('invoices.store'), adjustmentPayload($company, ['document_type' => $type->value]))
        ->assertSessionHasNoErrors();
    $note = FiscalDocument::where('document_type', $type)->firstOrFail();
    if ($fault === 'missing' || $fault === 'unknown') {
        $note->lines()->firstOrFail()->update(['reference_line_number' => $fault === 'missing' ? null : 99]);
    } elseif ($fault === 'other company') {
        $other = adjustmentCompany();
        $note->references_document_id = $other['invoice']->id;
    } elseif ($fault === 'other environment') {
        $note->environment = 'production';
    } elseif ($fault === 'wrong document number') {
        $note->references_document_no = 'FT UNKNOWN/1';
    }
    FiscalDocument::withoutEvents(fn () => $note->forceFill([
        'status' => FiscalDocumentStatus::Issued, 'document_no' => $type->value.' TEST/2', 'system_entry_at' => now(),
    ])->save());
    expect(fn () => app(FiscalDocumentPayloadBuilder::class)->signableObject($note->fresh()))->toThrow(DomainException::class);
})->with([FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote])
    ->with(['missing', 'unknown', 'other company', 'other environment', 'wrong document number']);
