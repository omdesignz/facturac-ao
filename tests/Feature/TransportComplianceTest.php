<?php

use App\Actions\CancelTransportDocument;
use App\Actions\IssueTransportDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Documents\PrintFormat;
use App\Fiscal\Documents\TransportDocumentOrigins;
use App\Fiscal\Documents\TransportDocumentPresenter;
use App\Fiscal\Saft\SaftExporter;
use App\Fiscal\Saft\SaftValidator;
use App\Fiscal\Saft\TransportDocumentSigner;
use App\FiscalDocumentStatus;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\Models\TransportDocumentSequence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function transportComplianceFixture(): array
{
    $publicKey = configureTestSaftSigning();
    $owner = User::factory()->withWorkspace('Transport compliance')->create();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $owner->currentWorkspace()->firstOrFail()->id]);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);

    return compact('owner', 'entity', 'establishment', 'publicKey');
}

function transportComplianceDraft(array $fixture, array $attributes = []): TransportDocument
{
    $draft = TransportDocument::factory()->create([
        'workspace_id' => $fixture['entity']->workspace_id,
        'legal_entity_id' => $fixture['entity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'created_by_user_id' => $fixture['owner']->id,
        'updated_by_user_id' => $fixture['owner']->id,
        'movement_date' => '2026-06-30',
        'movement_start_at' => '2026-07-01 09:00:00',
        'movement_end_at' => '2026-07-01 12:00:00',
        'recipient_tax_identification_number' => '5411111111',
        'net_total_minor' => 10000,
        'gross_total_minor' => 10000,
        ...$attributes,
    ]);
    TransportDocumentLine::factory()->create([
        'transport_document_id' => $draft->id,
        'unit_price_minor' => 5000,
        'quantity_units' => 2000,
        'net_amount_minor' => 10000,
        'order_references' => [['document_no' => 'FT ORIGEM/1', 'document_date' => '2026-06-01']],
    ]);

    return $draft;
}

function transportComplianceXml(array $fixture, string $from = '2026-06-01', string $to = '2026-07-31'): SimpleXMLElement
{
    $xml = app(SaftExporter::class)->export($fixture['entity'], CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    app(SaftValidator::class)->assertValid($xml);
    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', config('fiscal.saft.namespace'));

    return $root;
}

function transportComplianceInvoiceProfile(array $fixture, TransportDocument $guide): array
{
    return [
        'document_type' => 'FT', 'document_date' => '2026-07-02', 'due_date' => null,
        'currency_code' => 'AOA', 'establishment_public_id' => $fixture['establishment']->public_id,
        'customer_public_id' => null, 'customer' => ['name' => $guide->recipient_name, 'tax_identification_number' => $guide->recipient_tax_identification_number, 'country_code' => 'AO', 'address_line' => 'Luanda'],
        'notes' => null, 'references_document_public_id' => null, 'adjustment_reason' => null,
        'lines' => [[
            'origin_transport_line_public_id' => $guide->lines->first()->public_id,
            'operation_type' => 'SG', 'operation_date' => null,
            'product_code' => $guide->lines->first()->product_code, 'product_description' => 'Entrega parcial',
            'quantity' => '1', 'unit_of_measure' => 'UN', 'unit_price' => '50.00', 'discount_percentage' => '0',
            'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'tax_exemption_code' => null,
        ]],
    ];
}

test('transport signatures verify independently and chain across cancelled guides', function () {
    $fixture = transportComplianceFixture();
    $this->travelTo(CarbonImmutable::parse('2026-06-30 08:15:00', 'Africa/Luanda'));
    $first = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    $message = '2026-06-30;2026-06-30T08:15:00;'.$first->document_no.';100.00;';
    expect(openssl_verify($message, base64_decode($first->document_hash), $fixture['publicKey'], OPENSSL_ALGO_SHA1))->toBe(1)
        ->and($first->hash_control)->toBe('7')->and($first->document_hash)->toHaveLength(172);
    app(CancelTransportDocument::class)->execute($first, $fixture['owner'], 'Entrega adiada');
    $second = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    expect($second->previous_signature)->toBe($first->document_hash)
        ->and(openssl_verify('2026-06-30;2026-06-30T08:15:00;'.$second->document_no.';100.00;'.$first->document_hash,
            base64_decode($second->document_hash), $fixture['publicKey'], OPENSSL_ALGO_SHA1))->toBe(1);
    $second->gross_total_minor++;
    expect(app(TransportDocumentSigner::class)->verify($second))->toBeFalse();
});

test('a missing or incompatible signing key consumes no number and leaves the draft mutable', function () {
    $fixture = transportComplianceFixture();
    $draft = transportComplianceDraft($fixture);
    config()->set('agt.saft_signing.key_path', null);
    expect(fn () => app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1))->toThrow(BillingActionRefused::class);
    expect($draft->fresh()->isMutable())->toBeTrue()->and(TransportDocumentSequence::query()->count())->toBe(0);
    configureTestSaftSigning();
    $issued = app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1);
    expect($issued->issue_sequence)->toBe(1);
});

test('billing SAF-T excludes incoming outgoing and cancelled guides without changing their records', function () {
    $fixture = transportComplianceFixture();
    $out = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture, ['movement_direction' => 'incoming']), $fixture['owner'], 1);
    $cancelled = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    app(CancelTransportDocument::class)->execute($cancelled, $fixture['owner'], 'Cancelado');
    $root = transportComplianceXml($fixture, '2026-06-01', '2026-06-30');
    expect($root->xpath('//s:MovementOfGoods'))->toHaveCount(0)
        ->and($out->fresh()->movement_date->toDateString())->toBe('2026-06-30')
        ->and($out->fresh()->movement_start_at->toDateString())->toBe('2026-07-01')
        ->and($out->fresh()->lines->first()->order_references[0]['document_no'])->toBe('FT ORIGEM/1')
        ->and(TransportDocument::query()->count())->toBe(3)
        ->and(app(TransportDocumentSigner::class)->verify($out->fresh()))->toBeTrue();
    expect(transportComplianceXml($fixture, '2026-07-01', '2026-07-31')->xpath('//s:StockMovement'))->toHaveCount(0);
});

test('partial invoicing retains source references and marks the guide F only when issued', function () {
    $fixture = transportComplianceFixture();
    $guide = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    $invoice = app(SaveFiscalDocumentDraft::class)->execute($fixture['entity'], $fixture['owner'], transportComplianceInvoiceProfile($fixture, $guide));
    expect($invoice->lines->first()->order_references[0]['document_no'])->toBe($guide->document_no)
        ->and($guide->fresh()->latestInvoice())->toBeNull();
    $invoice->forceFill(['status' => FiscalDocumentStatus::Issued, 'document_no' => 'FT TESTE/1', 'issued_at' => now(), 'system_entry_at' => now(), 'issued_by_user_id' => $fixture['owner']->id])->save();
    $root = transportComplianceXml($fixture);
    expect($root->xpath('//s:MovementOfGoods'))->toHaveCount(0)
        ->and($guide->fresh()->latestInvoice()?->id)->toBe($invoice->id)
        ->and((string) $root->xpath('//s:Invoice/s:Line/s:OrderReferences/s:OriginatingON')[0])->toBe($guide->document_no)
        ->and($guide->fresh()->canCancel())->toBeFalse();
});

test('origin links reject cross tenant guides and guides cancelled after an invoice draft was saved', function () {
    $fixture = transportComplianceFixture();
    $guide = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    $profile = transportComplianceInvoiceProfile($fixture, $guide);
    $foreign = transportComplianceFixture();
    $profile['establishment_public_id'] = $foreign['establishment']->public_id;
    expect(fn () => app(SaveFiscalDocumentDraft::class)->execute($foreign['entity'], $foreign['owner'], $profile))->toThrow(ValidationException::class);
    $invoice = app(SaveFiscalDocumentDraft::class)->execute($fixture['entity'], $fixture['owner'], transportComplianceInvoiceProfile($fixture, $guide));
    app(CancelTransportDocument::class)->execute($guide, $fixture['owner'], 'Cancelado antes da factura');
    expect(fn () => app(TransportDocumentOrigins::class)->assertIssuable($invoice))->toThrow(ValidationException::class);
});

test('legacy checksums cannot masquerade as signatures or be silently chained', function () {
    $fixture = transportComplianceFixture();
    $guide = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    TransportDocument::query()->whereKey($guide)->update(['signature_algorithm' => null, 'document_hash' => hash('sha256', 'legacy')]);
    expect(transportComplianceXml($fixture)->xpath('//s:MovementOfGoods'))->toHaveCount(0);
    expect(app(TransportDocumentSigner::class)->verify($guide->fresh()))->toBeFalse();
    expect(fn () => app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1))->toThrow(BillingActionRefused::class);
    expect($guide->fresh()->document_hash)->toBe(hash('sha256', 'legacy'));
});

test('printed authenticity uses signature characters one eleven twenty one and thirty one', function () {
    $fixture = transportComplianceFixture();
    $guide = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    $view = app(TransportDocumentPresenter::class)->forPrint($guide);
    expect($view['authenticity']['signature_excerpt'])->toBe($guide->document_hash[0].$guide->document_hash[10].$guide->document_hash[20].$guide->document_hash[30]);
});

test('consignment issuance is blocked while the official schema excludes GC', function () {
    $fixture = transportComplianceFixture();
    $draft = transportComplianceDraft($fixture, ['document_type' => 'GC']);
    expect(fn () => app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1))->toThrow(BillingActionRefused::class, 'GC');
    expect($draft->fresh()->document_no)->toBeNull();
});

test('the guide footer prints the required wording and the genuine signature excerpt', function () {
    $fixture = transportComplianceFixture();
    $guide = app(IssueTransportDocument::class)->execute(transportComplianceDraft($fixture), $fixture['owner'], 1);
    $guide->software_validation_number = '999/AGT/2026';
    $document = app(TransportDocumentPresenter::class)->forPrint($guide);
    $format = app(PrintFormat::class);
    $html = view('transport-documents.pdf.guide', [
        'document' => $document,
        'logo' => null,
        'money' => fn (int $minor): string => $format->money($minor),
        'number' => fn (string $value, int $minimumDecimals = 0): string => $format->decimal($value, $minimumDecimals),
    ])->render();
    expect($html)->toContain('Este documento não serve de factura')
        ->toContain($document['authenticity']['signature_excerpt'].'-Processado por programa validado n.º 999/AGT/2026 / facturac.ao')
        ->toContain('Origem: FT ORIGEM/1')
        ->toContain('2026-06-30')->toContain('2026-07-01 09:00')
        ->not->toContain('Integridade');
});

test('the electronic invoice RSA key cannot be used for the SAF-T signature', function () {
    $fixture = transportComplianceFixture();
    $draft = transportComplianceDraft($fixture);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents(config('agt.saft_signing.key_path'), $pem);
    expect(fn () => app(IssueTransportDocument::class)->execute($draft, $fixture['owner'], 1))->toThrow(BillingActionRefused::class, '1024');
    expect($draft->fresh()->document_no)->toBeNull()->and(TransportDocumentSequence::query()->count())->toBe(0);
});
