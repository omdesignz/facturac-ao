<?php

use App\Actions\IssueTransportDocument;
use App\Actions\SaveQuote;
use App\Actions\SaveTransportDocumentDraft;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Saft\SaftExporter;
use App\Fiscal\Saft\SaftValidator;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\TransportDocument;
use App\Models\User;
use App\PaymentMethod;
use App\QuoteStatus;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function saftModuleFixture(): array
{
    config()->set('agt.software.company_tax_id', '5412345678');
    config()->set('agt.software.product_name', 'facturac.ao');
    config()->set('agt.software.company_name', 'VAP SOLUÇÕES, LDA');

    $owner = User::factory()->withWorkspace('VAP SAF-T')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'Comercial SAF-T, Lda.',
        'trade_name' => 'Comercial SAF-T',
        'tax_identification_number' => '5000000001',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'address_line' => 'Rua Rainha Ginga, 120',
        'municipality' => 'Luanda',
        'province_code' => 'LDA',
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

/** @param array<string, mixed> $fixture */
function saftModuleInvoice(
    array $fixture,
    FiscalDocumentType $documentType = FiscalDocumentType::Invoice,
    ?string $operationDate = null,
): FiscalDocument {
    $invoice = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'updated_by_user_id' => $fixture['owner']->id,
        'document_type' => $documentType,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
        'document_date' => '2026-06-10',
        'customer_name' => 'Cliente SAF-T, Lda.',
        'customer_tax_identification_number' => '5411111111',
        'customer_address' => 'Bairro Azul, Luanda',
    ]);
    $line = $invoice->lines()->create([
        'workspace_id' => $invoice->workspace_id,
        'legal_entity_id' => $invoice->legal_entity_id,
        'line_number' => 1,
        'operation_type' => 'SG',
        'operation_date' => $operationDate,
        'product_code' => 'ART-SAFT',
        'product_description' => 'Artigo de auditoria',
        'quantity_units' => 1_000,
        'quantity_scale' => 3,
        'unit_of_measure' => 'UN',
        'unit_price_base_minor' => 100_000,
        'unit_price_micros' => 100_000_000_000,
        'discount_rate_basis_points' => 0,
        'base_amount_minor' => 100_000,
        'settlement_amount_minor' => 0,
        'net_amount_minor' => 100_000,
        'tax_amount_minor' => 14_000,
        'gross_amount_minor' => 114_000,
    ]);
    $line->taxes()->create([
        'workspace_id' => $invoice->workspace_id,
        'legal_entity_id' => $invoice->legal_entity_id,
        'fiscal_document_id' => $invoice->id,
        'tax_type' => 'IVA',
        'tax_country_region' => 'AO',
        'tax_code' => 'NOR',
        'tax_rate_basis_points' => 1400,
        'tax_contribution_minor' => 14_000,
    ]);
    $invoice->forceFill([
        'status' => FiscalDocumentStatus::Valid,
        'agt_document_status' => 'V',
        'document_no' => $documentType->value.' SAFT26/1',
        'document_payload_sha256' => hash('sha256', 'invoice-saft-1'),
        'system_entry_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
        'frozen_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
        'issued_at' => CarbonImmutable::parse('2026-06-10 10:00:00'),
        'net_total_minor' => 100_000,
        'tax_payable_minor' => 14_000,
        'gross_total_minor' => 114_000,
    ])->saveQuietly();

    return $invoice->fresh();
}

/** @param array<string, mixed> $fixture */
function saftModuleReceipt(array $fixture, FiscalDocument $invoice): FiscalDocument
{
    $receipt = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'updated_by_user_id' => $fixture['owner']->id,
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
        'document_date' => '2026-06-12',
        'payment_date' => '2026-06-12',
        'payment_method' => PaymentMethod::BankTransfer,
        'payment_amount_minor' => 114_000,
        'customer_name' => $invoice->customer_name,
        'customer_tax_identification_number' => $invoice->customer_tax_identification_number,
        'customer_address' => $invoice->customer_address,
        'net_total_minor' => 114_000,
        'gross_total_minor' => 114_000,
    ]);
    FiscalDocumentSettlement::query()->create([
        'workspace_id' => $receipt->workspace_id,
        'legal_entity_id' => $receipt->legal_entity_id,
        'fiscal_document_id' => $receipt->id,
        'settled_document_id' => $invoice->id,
        'settled_document_no' => $invoice->document_no,
        'amount_minor' => 114_000,
    ]);
    $receipt->forceFill([
        'status' => FiscalDocumentStatus::Valid,
        'agt_document_status' => 'V',
        'document_no' => 'RC SAFT26/1',
        'document_payload_sha256' => hash('sha256', 'receipt-saft-1'),
        'system_entry_at' => CarbonImmutable::parse('2026-06-12 12:00:00'),
        'frozen_at' => CarbonImmutable::parse('2026-06-12 12:00:00'),
        'issued_at' => CarbonImmutable::parse('2026-06-12 12:00:00'),
    ])->saveQuietly();

    return $receipt->fresh();
}

/** @param array<string, mixed> $fixture */
function saftModuleQuote(array $fixture): Quote
{
    $quote = Quote::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'reference' => 'ORC 2026/0001',
        'status' => QuoteStatus::Draft,
        'issue_date' => '2026-06-05',
        'valid_until' => '2026-07-05',
        'customer_name' => 'Cliente Orçamento, Lda.',
        'customer_tax_identification_number' => '5412222222',
        'customer_address' => 'Talatona, Luanda',
        'net_total_minor' => 9_000,
        'tax_total_minor' => 1_260,
        'gross_total_minor' => 10_260,
    ]);
    $quote->lines()->create([
        'line_number' => 1,
        'operation_type' => 'SG',
        'product_code' => 'SERV-ORC',
        'product_description' => 'Serviço com desconto',
        'unit_of_measure' => 'UN',
        'quantity_units' => 1_000,
        'quantity_scale' => 3,
        'unit_price_minor' => 10_000,
        'discount_rate_basis_points' => 1000,
        'tax_type' => 'IVA',
        'tax_code' => 'NOR',
        'tax_percentage' => '14.00',
        'tax_exemption_code' => null,
        'net_amount_minor' => 9_000,
        'tax_amount_minor' => 1_260,
        'gross_amount_minor' => 10_260,
    ]);
    $quote->forceFill([
        'status' => QuoteStatus::Sent,
        'sent_at' => CarbonImmutable::parse('2026-06-05 15:00:00'),
    ])->save();

    return $quote->fresh('lines');
}

/** @param array<string, mixed> $fixture */
function saftModuleTransport(
    array $fixture,
    TransportDocumentType $documentType = TransportDocumentType::TransportGuide,
): TransportDocument {
    $draft = app(SaveTransportDocumentDraft::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        [
            'document_type' => $documentType->value,
            'establishment_public_id' => $fixture['establishment']->public_id,
            'customer_public_id' => null,
            'movement_date' => '2026-06-11',
            'movement_start_at' => '2026-06-11 08:00',
            'movement_end_at' => '2026-06-11 10:00',
            'recipient' => [
                'name' => 'Armazém Destino, Lda.',
                'tax_identification_number' => '5413333333',
                'country_code' => 'AO',
                'address' => 'Estrada de Catete, Km 12',
                'city' => 'Viana',
                'province' => 'Luanda',
            ],
            'origin' => [
                'address' => 'Rua Rainha Ginga, 120',
                'city' => 'Luanda',
                'province' => 'Luanda',
                'country_code' => 'AO',
            ],
            'destination' => [
                'address' => 'Estrada de Catete, Km 12',
                'city' => 'Viana',
                'province' => 'Luanda',
                'country_code' => 'AO',
            ],
            'transporter' => [
                'name' => 'Transportes SAF-T',
                'tax_identification_number' => '5412345678',
                'vehicle_registration' => 'LD-10-20-AA',
            ],
            'gross_weight_grams' => 50_000,
            'package_count' => 2,
            'notes' => null,
            'lines' => [[
                'catalogue_item_public_id' => null,
                'product_code' => 'ART-SAFT',
                'product_description' => 'Artigo de auditoria',
                'quantity_units' => 2_000,
                'quantity_scale' => 3,
                'unit_of_measure' => 'UN',
                'unit_price_minor' => 100_000,
            ]],
        ],
    );

    return app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1);
}

/** @param array<string, mixed> $fixture */
function saftModuleMixedRecords(array $fixture): array
{
    $invoice = saftModuleInvoice($fixture);

    return [
        'invoice' => $invoice,
        'receipt' => saftModuleReceipt($fixture, $invoice),
        'transport' => saftModuleTransport($fixture),
        'quote' => saftModuleQuote($fixture),
    ];
}

test('the control centre previews every billing ledger in the selected period', function () {
    $fixture = saftModuleFixture();
    saftModuleMixedRecords($fixture);

    $this->actingAs($fixture['owner'])
        ->get(route('saft.index', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Saft/Index')
            ->where('summary.counts.sales_invoices', 1)
            ->where('summary.counts.movement_of_goods', 1)
            ->where('summary.counts.working_documents', 1)
            ->where('summary.counts.payments', 1)
            ->where('summary.counts.customers', 4)
            ->where('summary.counts.suppliers', 0)
            ->where('summary.counts.products', 2)
            ->where('summary.exportable', true)
            ->where('schema.version', '1.01_01')
        );
});

test('the control centre blocks incomplete numbered records and the download fails safely', function () {
    $fixture = saftModuleFixture();
    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'updated_by_user_id' => $fixture['owner']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT LEGACY26/1',
        'document_date' => '2026-06-10',
        'system_entry_at' => '2026-06-10 10:00:00',
        'issued_at' => '2026-06-10 10:00:00',
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('saft.index', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.exportable', false)
            ->where('summary.readiness.0.key', 'records')
            ->where('summary.readiness.0.ready', false)
            ->where('summary.readiness.0.blocking', true)
        );

    $this->get(route('saft.export', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertRedirect(route('saft.index', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertSessionHas('error');
});

test('a mixed billing export passes the pinned official AGT schema', function () {
    $fixture = saftModuleFixture();
    saftModuleMixedRecords($fixture);
    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    app(SaftValidator::class)->assertValid($xml);

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    expect($root->xpath('//s:SalesInvoices/s:Invoice'))->toHaveCount(1)
        ->and($root->xpath('//s:MovementOfGoods/s:StockMovement'))->toHaveCount(1)
        ->and($root->xpath('//s:WorkingDocuments/s:WorkDocument'))->toHaveCount(1)
        ->and($root->xpath('//s:Payments/s:Payment'))->toHaveCount(1)
        ->and((string) $root->xpath('//s:WorkingDocuments/s:WorkDocument/s:WorkType')[0])
        ->toBe('OR')
        ->and((string) $root->xpath('//s:WorkingDocuments/s:WorkDocument/s:Line/s:SettlementAmount')[0])
        ->toBe('10.00')
        ->and((string) $root->xpath('//s:MovementOfGoods/s:StockMovement/s:MovementType')[0])
        ->toBe('GT')
        ->and((string) $root->xpath('//s:MovementOfGoods/s:StockMovement/s:MovementStartTime')[0])
        ->toBe('2026-06-11T08:00:00')
        ->and((string) $root->xpath('//s:MovementOfGoods/s:StockMovement/s:MovementEndTime')[0])
        ->toBe('2026-06-11T10:00:00')
        ->and((string) $root->xpath('//s:Payments/s:Payment/s:PaymentType')[0])
        ->toBe('RC');
});

test('a GF document carries its operation date into a schema-valid SAF-T invoice', function () {
    $fixture = saftModuleFixture();
    saftModuleInvoice(
        $fixture,
        FiscalDocumentType::GenericInvoice,
        '2026-06-09',
    );

    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    app(SaftValidator::class)->assertValid($xml);

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    expect((string) $root->xpath('//s:Invoice/s:InvoiceType')[0])->toBe('GF')
        ->and((string) $root->xpath('//s:Invoice/s:Line/s:TaxPointDate')[0])
        ->toBe('2026-06-09');
});

test('every supported transport document type passes the SAF-T movement schema', function () {
    $fixture = saftModuleFixture();

    foreach (TransportDocumentType::cases() as $documentType) {
        saftModuleTransport($fixture, $documentType);
    }

    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    app(SaftValidator::class)->assertValid($xml);

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    expect(array_map(
        static fn (SimpleXMLElement $type): string => (string) $type,
        $root->xpath('//s:MovementOfGoods/s:StockMovement/s:MovementType'),
    ))->toBe(array_column(TransportDocumentType::cases(), 'value'))
        ->and($root->xpath('//s:MovementOfGoods/s:StockMovement/s:CustomerID'))->toHaveCount(3)
        ->and($root->xpath('//s:MovementOfGoods/s:StockMovement/s:SupplierID'))->toHaveCount(1)
        ->and($root->xpath('//s:MasterFiles/s:Supplier'))->toHaveCount(1)
        ->and((string) $root->xpath('//s:MovementOfGoods/s:StockMovement/s:SupplierID')[0])
        ->toBe((string) $root->xpath('//s:MasterFiles/s:Supplier/s:SupplierID')[0]);
});

test('an empty billing period still produces a schema-valid SAF-T file', function () {
    $fixture = saftModuleFixture();
    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    app(SaftValidator::class)->assertValid($xml);

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    expect((string) $root->xpath('//s:SalesInvoices/s:NumberOfEntries')[0])->toBe('0')
        ->and((string) $root->xpath('//s:MovementOfGoods/s:NumberOfMovementLines')[0])->toBe('0')
        ->and((string) $root->xpath('//s:WorkingDocuments/s:NumberOfEntries')[0])->toBe('0')
        ->and((string) $root->xpath('//s:Payments/s:NumberOfEntries')[0])->toBe('0');
});

test('a rejected quote is exported as annulled and excluded from working document totals', function () {
    $fixture = saftModuleFixture();
    $quote = saftModuleQuote($fixture);
    $quote->forceFill([
        'status' => QuoteStatus::Rejected,
        'decided_at' => CarbonImmutable::parse('2026-06-07 09:00:00'),
    ])->save();

    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    app(SaftValidator::class)->assertValid($xml);

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    expect((string) $root->xpath('//s:WorkingDocuments/s:TotalDebit')[0])->toBe('0.00')
        ->and((string) $root->xpath('//s:WorkDocument/s:DocumentStatus/s:WorkStatus')[0])->toBe('A')
        ->and((string) $root->xpath('//s:WorkDocument/s:DocumentStatus/s:Reason')[0])
        ->toBe('Orçamento recusado pelo cliente');
});

test('the download is validated and a cross-year file is refused', function () {
    $fixture = saftModuleFixture();
    saftModuleMixedRecords($fixture);

    $this->actingAs($fixture['owner'])
        ->get(route('saft.export', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertDownload();

    $this->get(route('saft.export', ['from' => '2025-12-01', 'to' => '2026-01-31']))
        ->assertSessionHasErrors('to');
});

test('schema validation fails closed when the generated XML is changed incompatibly', function () {
    $fixture = saftModuleFixture();
    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    $invalid = str_replace(
        '<AuditFileVersion>1.01_01</AuditFileVersion>',
        '<AuditFileVersion>INVALID</AuditFileVersion>',
        $xml,
    );

    expect(fn () => app(SaftValidator::class)->assertValid($invalid))
        ->toThrow(RuntimeException::class, 'não cumpre o esquema AGT');
});

test('a quote freezes when sent because it is now a SAF-T working document', function () {
    $fixture = saftModuleFixture();
    $quote = saftModuleQuote($fixture);

    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->status->isEditable())->toBeFalse();

    expect(fn () => $quote->lines->first()->update(['quantity_units' => 2_000]))
        ->toThrow(DomainException::class);

    expect(fn () => app(SaveQuote::class)->execute(
        $fixture['legalEntity'],
        $fixture['owner'],
        [],
        $quote,
    ))->toThrow(BillingActionRefused::class, 'já foi enviado');
});
