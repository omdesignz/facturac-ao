<?php

use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\V1_2\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function issuedPayloadFixture(): FiscalDocument
{
    $user = User::factory()->withWorkspace()->create();
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
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
        'customer_name' => 'Comércio Kilamba, Lda.',
        'customer_tax_identification_number' => '5411111111',
        'customer_country_code' => 'AO',
        'document_date' => '2026-08-02',
        'settlement_total_minor' => 1_000,
        'net_total_minor' => 9_000,
        'tax_payable_minor' => 1_260,
        'gross_total_minor' => 10_260,
    ]);
    $line = FiscalDocumentLine::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'fiscal_document_id' => $document->id,
        'operation_type' => 'TB',
        'quantity_units' => 10_000,
        'unit_price_base_minor' => 10_000,
        'unit_price_micros' => 90_000_000,
        'discount_rate_basis_points' => 1_000,
        'base_amount_minor' => 10_000,
        'settlement_amount_minor' => 1_000,
        'net_amount_minor' => 9_000,
        'tax_amount_minor' => 1_260,
        'gross_amount_minor' => 10_260,
    ]);
    FiscalDocumentLineTax::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'fiscal_document_id' => $document->id,
        'fiscal_document_line_id' => $line->id,
        'tax_contribution_minor' => 1_260,
    ]);
    $document->update([
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => 'FT TESTE/1',
        'system_entry_at' => '2026-08-02 10:15:20',
        'frozen_at' => '2026-08-02 10:15:20',
        'issued_at' => '2026-08-02 10:15:20',
    ]);

    return $document->fresh(['legalEntity', 'lines.taxes']) ?? $document;
}

test('the v1.2 signable object contains only the required fiscal identity and totals', function () {
    $document = issuedPayloadFixture();
    $payload = (new FiscalDocumentPayloadBuilder(new FiscalCalculator))->signableObject($document);
    $json = (new CanonicalJson)->encode($payload);

    expect(array_keys($payload))->toBe([
        'documentNo',
        'taxRegistrationNumber',
        'documentType',
        'documentDate',
        'customerTaxID',
        'customerCountry',
        'companyName',
        'documentTotals',
    ])->and($json)->toBe('{"companyName":"Comércio Kilamba, Lda.","customerCountry":"AO","customerTaxID":"5411111111","documentDate":"2026-08-02","documentNo":"FT TESTE/1","documentTotals":{"grossTotal":102.6,"netTotal":90,"taxPayable":12.6},"documentType":"FT","taxRegistrationNumber":"5000000001"}');
});

test('the current contract maps base price before discount and unit price after discount', function () {
    $payload = (new FiscalDocumentPayloadBuilder(new FiscalCalculator))->document(issuedPayloadFixture());
    $json = json_decode((new CanonicalJson)->encode($payload), true, flags: JSON_THROW_ON_ERROR);

    expect($json['lines'][0]['operationType'])->toBe('TB')
        ->and($json['lines'][0]['unitPriceBase'])->toBe(100)
        ->and($json['lines'][0]['unitPrice'])->toBe(90)
        ->and($json['lines'][0]['debitAmount'])->toBe(90)
        ->and($json['lines'][0]['settlementAmount'])->toBe(10)
        ->and($json['lines'][0]['taxes'][0]['taxContribution'])->toBe(12.6);
});

test('a mutable draft cannot be projected into a signable payload', function () {
    $document = issuedPayloadFixture();
    FiscalDocument::withoutEvents(fn () => $document->forceFill([
        'status' => FiscalDocumentStatus::Draft,
    ])->save());

    (new FiscalDocumentPayloadBuilder(new FiscalCalculator))->signableObject($document->fresh());
})->throws(DomainException::class);

test('issued documents reject business mutations', function () {
    $document = issuedPayloadFixture();
    $document->customer_name = 'Alteração indevida';
    $document->save();
})->throws(DomainException::class);
