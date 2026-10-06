<?php

use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Exceptions\UnsupportedAgtSchema;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Agt\V2_0\AgtRequestPayloadBuilder;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtConnection;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\LegalEntity;
use App\PaymentMethod;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function schemaTwoPayloadDocument(FiscalDocumentType $type = FiscalDocumentType::Invoice): FiscalDocument
{
    $document = new FiscalDocument([
        'payload_schema_version' => '2.0',
        'document_type' => $type,
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => $type->value.' TESTE/1',
        'agt_document_status' => 'N',
        'document_date' => '2026-10-01',
        'system_entry_at' => '2026-10-01 10:00:00',
        'customer_name' => 'Cliente Exemplo',
        'customer_tax_identification_number' => '5411111111',
        'customer_country_code' => 'AO',
        'currency_code' => 'AOA',
        'exchange_rate_micro' => 1_000_000,
        'net_total_minor' => 9_000,
        'tax_payable_minor' => 1_260,
        'gross_total_minor' => 10_260,
        'references_document_no' => $type->isAdjustment() ? 'FT ORIGINAL/1' : null,
        'adjustment_reason' => $type->isAdjustment() ? 'Correcção parcial' : null,
        'payment_method' => $type->isReceipt() ? PaymentMethod::Cash : null,
        'payment_date' => $type->isReceipt() ? '2026-10-01' : null,
    ]);
    $line = new FiscalDocumentLine([
        'line_number' => 1,
        'operation_type' => 'TB',
        'operation_date' => $type->requiresLineOperationDate() ? '2026-09-30' : null,
        'product_code' => 'PROD-1',
        'product_description' => 'Produto de teste',
        'quantity_units' => 10_000,
        'quantity_scale' => 4,
        'unit_of_measure' => 'UN',
        'unit_price_base_minor' => 10_000,
        'unit_price_micros' => 90_000_000,
        'net_amount_minor' => 9_000,
        'settlement_amount_minor' => 1_000,
    ]);
    $line->setRelation('taxes', collect([new FiscalDocumentLineTax([
        'tax_type' => 'IVA', 'tax_country_region' => 'AO', 'tax_code' => 'NOR',
        'tax_rate_basis_points' => 1_400, 'tax_contribution_minor' => 1_260,
    ])]));
    $document->setRelation('legalEntity', new LegalEntity([
        'tax_identification_number' => '5000000001', 'main_cae_code' => '62010',
    ]));
    $document->setRelation('lines', collect([$line]));
    $document->setRelation('withholdings', collect());
    $document->setRelation('settlements', collect());

    return $document;
}

test('schema 2 maps discounted prices and integer line numbers in the correct direction', function (FiscalDocumentType $type, string $amountField) {
    $document = schemaTwoPayloadDocument($type);
    $payload = app(FiscalDocumentPayloadBuilder::class)->document($document);
    $json = json_decode(app(CanonicalJson::class)->encode($payload), true, flags: JSON_THROW_ON_ERROR);
    $otherField = $amountField === 'creditAmount' ? 'debitAmount' : 'creditAmount';

    expect($json['lines'][0]['lineNumber'])->toBe(1)
        ->and($json['lines'][0]['unitPriceBase'])->toBe(100)
        ->and($json['lines'][0]['unitPrice'])->toBe(90)
        ->and($json['lines'][0][$amountField])->toBe(90)
        ->and($json['lines'][0])->not->toHaveKey($otherField)
        ->and($json['lines'][0]['settlementAmount'])->toBe(10)
        ->and($json['lines'][0]['taxes'][0]['taxContribution'])->toBe(12.6)
        ->and($json)->not->toHaveKey('paymentReceipt')
        ->and(app(FiscalDocumentPayloadBuilder::class)->signableObject($document)['documentTotals'])
        ->toEqual($payload['documentTotals']);

    if ($type === FiscalDocumentType::GenericInvoice) {
        expect($json['lines'][0]['operationDate'])->toBe('2026-09-30');
    }
})->with([
    'FT' => [FiscalDocumentType::Invoice, 'creditAmount'],
    'FR' => [FiscalDocumentType::InvoiceReceipt, 'creditAmount'],
    'GF' => [FiscalDocumentType::GenericInvoice, 'creditAmount'],
    'ND' => [FiscalDocumentType::DebitNote, 'creditAmount'],
    'NC' => [FiscalDocumentType::CreditNote, 'debitAmount'],
]);

test('schema 2 foreign totals remain in document currency with the converted kwanza amount', function () {
    $document = schemaTwoPayloadDocument();
    $document->fill(['currency_code' => 'USD', 'exchange_rate_micro' => 912_500_000]);
    $payload = app(FiscalDocumentPayloadBuilder::class)->document($document);
    $totals = json_decode(app(CanonicalJson::class)->encode($payload['documentTotals']), true, flags: JSON_THROW_ON_ERROR);

    expect($totals)->toBe([
        'currency' => ['currencyAmount' => 93622.5, 'currencyCode' => 'USD', 'exchangeRate' => 912.5],
        'grossTotal' => 102.6, 'netTotal' => 90, 'taxPayable' => 12.6,
    ]);
});

test('schema 2 truncates credits and rounds debits upwards without losing intermediate precision', function (FiscalDocumentType $type, int $netMinor) {
    $calculation = (new FiscalCalculator)->calculate([[
        'operation_type' => 'TB', 'product_code' => 'P', 'product_description' => 'Produto',
        'quantity' => '1.2345', 'unit_of_measure' => 'UN', 'unit_price' => '10.00',
        'discount_percentage' => '0', 'tax_type' => 'IVA', 'tax_code' => 'NOR',
        'tax_percentage' => '14', 'tax_exemption_code' => null,
    ]], $type);

    expect($calculation->netTotalMinor)->toBe($netMinor)
        ->and($calculation->taxPayableMinor)->toBe(173);
})->with([
    'truncated credit' => [FiscalDocumentType::Invoice, 1234],
    'rounded debit' => [FiscalDocumentType::CreditNote, 1235],
]);

test('schema 2 signs every transmitted software detail including the integer key version', function () {
    $signer = new class implements JwsSigner
    {
        public array $payloads = [];

        public function sign(array $payload, string $keyReference): string
        {
            $this->payloads[$keyReference] = $payload;

            return 'synthetic.signature';
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    };
    config()->set('agt.software.signature_version', 2);
    $connection = new AgtConnection([
        'schema_version' => '2.0', 'establishment_number' => 'SEDE',
        'product_id' => 'facturac.ao', 'product_version' => '1.0.0',
        'software_validation_number' => 'TEST-ONLY',
        'software_key_reference' => 'software/test', 'taxpayer_key_reference' => 'taxpayer/test',
    ]);
    $builder = new AgtRequestPayloadBuilder($signer, app(FiscalDocumentPayloadBuilder::class));
    $payload = $builder->listSeries($connection, new LegalEntity([
        'tax_identification_number' => '5000000001',
    ]), CarbonImmutable::parse('2026-10-01T10:00:00Z'));

    expect($payload['schemaVersion'])->toBe('2.0')
        ->and($payload['seriesYear'])->toBe(2026)
        ->and($payload['softwareInfo']['softwareInfoDetail']['signatureVersion'])->toBe(2)
        ->and($signer->payloads['software/test'])->toBe($payload['softwareInfo']['softwareInfoDetail'])
        ->and($signer->payloads['taxpayer/test'])->toBe(['taxRegistrationNumber' => '5000000001']);
});

test('the new mapper refuses to rewrite an old issued document', function () {
    $document = schemaTwoPayloadDocument();
    $document->payload_schema_version = '1.2';

    expect(fn () => app(FiscalDocumentPayloadBuilder::class)->document($document))
        ->toThrow(UnsupportedAgtSchema::class);
});
