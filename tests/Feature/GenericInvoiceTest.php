<?php

use App\Fiscal\Documents\V1_2\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, legal_entity: LegalEntity, establishment: Establishment}
 */
function genericInvoiceCompany(): array
{
    $user = User::factory()->withWorkspace('VAP Factura Genérica')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5000000001',
        'main_cae_code' => '46900',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return [
        'user' => $user,
        'legal_entity' => $legalEntity,
        'establishment' => $establishment,
    ];
}

/**
 * @param  array{establishment: Establishment}  $company
 * @return array<string, mixed>
 */
function genericInvoicePayload(array $company): array
{
    return [
        'document_type' => FiscalDocumentType::GenericInvoice->value,
        'document_date' => '2026-08-13',
        'due_date' => '2026-09-12',
        'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => null,
        'customer' => [
            'name' => 'Cliente Genérico, Lda.',
            'tax_identification_number' => '5411111111',
            'country_code' => 'AO',
            'address_line' => 'Luanda',
        ],
        'notes' => 'Operações agregadas no período.',
        'lines' => [[
            'operation_type' => 'TB',
            'operation_date' => '2026-08-12',
            'product_code' => 'ART-GF-1',
            'product_description' => 'Operação agregada',
            'quantity' => '2',
            'unit_of_measure' => 'un',
            'unit_price' => '1000.00',
            'discount_percentage' => '10',
            'tax' => [
                'type' => 'IVA',
                'code' => 'NOR',
                'percentage' => '14',
                'exemption_code' => null,
            ],
        ]],
    ];
}

test('generic invoices are offered as issuable documents', function () {
    $company = genericInvoiceCompany();

    expect(FiscalDocumentType::issuable())
        ->toContain(FiscalDocumentType::GenericInvoice)
        ->and(FiscalDocumentType::GenericInvoice->requiresLineOperationDate())->toBeTrue();

    $this->actingAs($company['user'])
        ->get(route('invoices.create', ['type' => 'GF']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.document_type', 'GF')
            ->where('documentTypes.2.value', 'GF')
            ->where('documentTypes.2.requires_line_operation_date', true)
            ->where('document.lines.0.operation_date', now('Africa/Luanda')->toDateString())
        );
});

test('every generic invoice line requires an operation date', function () {
    $company = genericInvoiceCompany();
    $payload = genericInvoicePayload($company);
    unset($payload['lines'][0]['operation_date']);

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors('lines.0.operation_date');
});

test('a generic invoice stores and sends each line operation date to AGT', function () {
    $company = genericInvoiceCompany();

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), genericInvoicePayload($company))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document = FiscalDocument::query()
        ->where('document_type', FiscalDocumentType::GenericInvoice)
        ->with('lines')
        ->sole();

    expect($document->lines)->toHaveCount(1)
        ->and($document->lines->first()->operation_date->toDateString())->toBe('2026-08-12')
        ->and($document->settlement_total_minor)->toBe(20_000)
        ->and($document->net_total_minor)->toBe(180_000);

    FiscalDocument::withoutEvents(fn () => $document->forceFill([
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => 'GF TESTE/1',
        'system_entry_at' => '2026-08-13 10:00:00',
        'frozen_at' => '2026-08-13 10:00:00',
        'issued_at' => '2026-08-13 10:00:00',
    ])->save());

    $agtPayload = app(FiscalDocumentPayloadBuilder::class)->document($document->fresh());

    expect($agtPayload['documentType'])->toBe('GF')
        ->and($agtPayload['lines'][0]['operationDate'])->toBe('2026-08-12')
        ->and((string) $agtPayload['lines'][0]['settlementAmount'])->toBe('200');
});
