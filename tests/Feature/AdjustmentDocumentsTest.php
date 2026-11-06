<?php

use App\Actions\IssueFiscalDocument;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalSeries;
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
        $line = FiscalDocumentLine::factory()->create([
            'fiscal_document_id' => $invoice->id,
            'line_number' => $lineNumber,
            'product_code' => 'SAME-PRODUCT',
            'product_description' => "Artigo {$lineNumber}",
            'quantity_units' => 20_000,
            'unit_price_base_minor' => 1000_00,
            'unit_price_micros' => 1_000_000_000,
        ]);
        FiscalDocumentLineTax::factory()->create(['fiscal_document_line_id' => $line->id]);
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

/**
 * A note that has already credited part of line 3 of the company's invoice.
 */
function earlierCreditNote(array $company, int $quantityUnits, FiscalDocumentStatus $status = FiscalDocumentStatus::Issued): FiscalDocument
{
    $note = FiscalDocument::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'document_type' => FiscalDocumentType::CreditNote,
        'references_document_id' => $company['invoice']->id,
        'references_document_no' => 'FT TESTE/1',
        'adjustment_reason' => 'Devolução anterior',
    ]);
    FiscalDocumentLine::factory()->create([
        'fiscal_document_id' => $note->id,
        'reference_line_number' => 3,
        'quantity_units' => $quantityUnits,
    ]);

    if ($status !== FiscalDocumentStatus::Draft) {
        FiscalDocument::withoutEvents(fn () => $note->forceFill([
            'status' => $status,
            'document_no' => "NC TESTE/{$note->id}",
            'system_entry_at' => now(),
        ])->save());
    }

    return $note;
}

test('a credit note cannot credit more of a line than the original has', function () {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company);
    $payload['lines'][0]['quantity'] = '2.0001';

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors(['lines.0.quantity' => 'Só pode creditar até 2 un de Artigo 3.']);
    expect(FiscalDocument::where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0);
});

test('lines of one note crediting the same original line share what is left of it', function () {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company);
    $payload['lines'][] = array_replace($payload['lines'][0], ['quantity' => '1.5']);

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors(['lines.1.quantity' => 'Só pode creditar até 1 un de Artigo 3.']);

    $payload['lines'][1]['quantity'] = '1';

    $this->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
});

test('a credit note only credits what earlier notes have left, drafts aside', function (FiscalDocumentStatus $status) {
    $company = adjustmentCompany();
    earlierCreditNote($company, 15_000, $status);
    earlierCreditNote($company, 20_000, FiscalDocumentStatus::Draft);
    $payload = adjustmentPayload($company);

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors(['lines.0.quantity' => 'Só pode creditar até 0.5 un de Artigo 3.']);

    $payload['lines'][0]['quantity'] = '0.5';

    $this->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
})->with([
    'issued' => FiscalDocumentStatus::Issued,
    'validated' => FiscalDocumentStatus::Valid,
    'returned by the AGT' => FiscalDocumentStatus::Invalid,
]);

test('a credit note credits a line at the price, discount and tax it was charged', function (string $field, array $change, string $message) {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company);
    $payload['lines'][0] = array_replace_recursive($payload['lines'][0], $change);

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors(["lines.0.{$field}" => $message]);
    expect(FiscalDocument::where('document_type', FiscalDocumentType::CreditNote)->count())->toBe(0);
})->with([
    'another price' => ['unit_price', ['unit_price' => '900.00'], 'O preço unitário tem de ser o do documento original: 1000.'],
    'a discount' => ['discount_percentage', ['discount_percentage' => '5'], 'O desconto tem de ser o do documento original: 0%.'],
    'an exemption' => ['tax', ['tax' => ['code' => 'ISE', 'percentage' => '0', 'exemption_code' => 'M02']], 'O imposto tem de ser o do documento original: IVA 14%.'],
]);

test('a credit note keeps the currency of the document it corrects', function () {
    $company = adjustmentCompany();

    $this->actingAs($company['user'])->post(route('invoices.store'), adjustmentPayload($company, [
        'currency_code' => 'USD',
        'exchange_rate' => '900',
    ]))->assertSessionHasErrors(['currency_code' => 'A nota de crédito tem de usar a moeda do documento original (AOA).']);
});

test('a debit note may charge more, and at another price, than the original line', function () {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company, ['document_type' => FiscalDocumentType::DebitNote->value]);
    $payload['lines'][0] = array_replace($payload['lines'][0], ['quantity' => '5', 'unit_price' => '2000.00']);

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasNoErrors();
});

test('issuing a credit note checks again what is left and what was charged, without spending a number', function (string $change, string $message) {
    $company = adjustmentCompany();
    $payload = adjustmentPayload($company);
    $payload['lines'][0]['quantity'] = '2';
    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
    $draft = FiscalDocument::where('document_type', FiscalDocumentType::CreditNote)->firstOrFail();

    if ($change === 'credited meanwhile') {
        earlierCreditNote($company, 5_000);
    } else {
        $draft->lines()->firstOrFail()->taxes()->firstOrFail()->update(['tax_rate_basis_points' => 700]);
    }

    $connection = AgtConnection::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
    ]);
    $series = FiscalSeries::factory()->create([
        'workspace_id' => $company['legal_entity']->workspace_id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'agt_connection_id' => $connection->id,
        'environment' => $connection->environment->value,
        'document_type' => FiscalDocumentType::CreditNote,
        'series_code' => 'NC26TEST',
    ]);

    expect(fn () => app(IssueFiscalDocument::class)->execute($draft, $company['user'], $series->public_id, $draft->revision))
        ->toThrow(FiscalFinalizationBlocked::class, $message);
    expect($draft->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($draft->fresh()->document_no)->toBeNull()
        ->and($series->fresh()->next_number)->toBe(1);
})->with([
    'credited meanwhile' => ['credited meanwhile', 'Linha 1: Só pode creditar até 1.5 un de Artigo 3.'],
    'tax changed' => ['tax changed', 'Linha 1: O imposto tem de ser o do documento original: IVA 14%.'],
]);
