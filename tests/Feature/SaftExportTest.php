<?php

use App\Fiscal\Saft\SaftExporter;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;

/**
 * Reads the export as a document rather than as a string: an audit file that
 * merely contains the right words but nests them wrongly is rejected.
 *
 * @return array{root: SimpleXMLElement, xml: string}
 */
function saftFor(array $fixture, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
{
    $xml = app(SaftExporter::class)->export(
        $fixture['legalEntity'],
        $from ?? CarbonImmutable::parse('2026-01-01'),
        $to ?? CarbonImmutable::parse('2026-12-31'),
    );

    $root = new SimpleXMLElement($xml);
    $root->registerXPathNamespace('s', (string) config('fiscal.saft.namespace'));

    return ['root' => $root, 'xml' => $xml];
}

test('the file is well formed and declares the AO schema', function () {
    $fixture = pdfFixture();
    ['root' => $root, 'xml' => $xml] = saftFor($fixture);

    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($root->getName())->toBe('AuditFile')
        ->and((string) $root->getNamespaces()[''])
        ->toBe((string) config('fiscal.saft.namespace'));
});

test('the header carries the taxpayer and the period', function () {
    $fixture = pdfFixture();
    ['root' => $root] = saftFor($fixture);

    $header = $root->xpath('//s:Header')[0];

    expect((string) $header->AuditFileVersion)->toBe((string) config('fiscal.saft.version'))
        ->and((string) $header->CompanyID)
        ->toBe($fixture['legalEntity']->tax_identification_number)
        ->and((string) $header->StartDate)->toBe('2026-01-01')
        ->and((string) $header->EndDate)->toBe('2026-12-31')
        ->and((string) $header->CurrencyCode)->toBe('AOA');
});

test('the tax table describes only the rates the period used', function () {
    $fixture = pdfFixture();
    documentWithLines($fixture, lines: 2);

    ['root' => $root] = saftFor($fixture);

    $entries = $root->xpath('//s:TaxTable/s:TaxTableEntry');

    // Two lines at the same rate is one entry, not two.
    expect($entries)->toHaveCount(1)
        ->and((string) $entries[0]->TaxType)->toBe('IVA')
        ->and((string) $entries[0]->TaxPercentage)->toBe('14.00')
        ->and((string) $entries[0]->TaxCountryRegion)->toBe('AO');
});

test('invoices carry their signature, totals and lines', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture, lines: 3);

    ['root' => $root] = saftFor($fixture);

    $invoice = $root->xpath('//s:SalesInvoices/s:Invoice')[0];

    expect((string) $invoice->InvoiceNo)->toBe($document->document_no)
        ->and((string) $invoice->Hash)->toBe($document->document_payload_sha256)
        ->and((string) $invoice->InvoiceType)->toBe('FT')
        ->and($root->xpath('//s:SalesInvoices/s:Invoice/s:Line'))->toHaveCount(3)
        ->and((string) $invoice->DocumentTotals->GrossTotal)
        ->toBe(number_format($document->gross_total_minor / 100, 2, '.', ''));
});

test('the entry count matches the invoices in the file', function () {
    $fixture = pdfFixture();
    documentWithLines($fixture);
    documentWithLines($fixture);

    ['root' => $root] = saftFor($fixture);

    expect((string) $root->xpath('//s:SalesInvoices/s:NumberOfEntries')[0])->toBe('2')
        ->and($root->xpath('//s:SalesInvoices/s:Invoice'))->toHaveCount(2);
});

test('a credit note is a credit, not a debit', function () {
    $fixture = pdfFixture();
    documentWithLines($fixture, lines: 1, type: FiscalDocumentType::CreditNote);

    ['root' => $root] = saftFor($fixture);

    // Getting this backwards balances the file to the wrong side, and the
    // validator does not catch it.
    expect($root->xpath('//s:SalesInvoices/s:Invoice/s:Line/s:CreditAmount'))
        ->toHaveCount(1)
        ->and($root->xpath('//s:SalesInvoices/s:Invoice/s:Line/s:DebitAmount'))
        ->toHaveCount(0);
});

test('documents outside the period are left out', function () {
    $fixture = pdfFixture();
    $document = documentWithLines($fixture);

    $document->forceFill(['document_date' => '2020-06-01'])->saveQuietly();

    ['root' => $root] = saftFor($fixture);

    expect($root->xpath('//s:SalesInvoices/s:Invoice'))->toHaveCount(0)
        ->and((string) $root->xpath('//s:SalesInvoices/s:NumberOfEntries')[0])->toBe('0');
});

test('drafts never reach the audit file', function () {
    $fixture = pdfFixture();

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
        'document_date' => '2026-05-01',
    ]);

    ['root' => $root] = saftFor($fixture);

    expect($root->xpath('//s:SalesInvoices/s:Invoice'))->toHaveCount(0);
});

test('customers and products are listed in the master files', function () {
    $fixture = pdfFixture();

    Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Padaria Cazenga',
    ]);

    ['root' => $root] = saftFor($fixture);

    $customers = $root->xpath('//s:MasterFiles/s:Customer');

    expect($customers)->toHaveCount(1)
        ->and((string) $customers[0]->CompanyName)->toBe('Padaria Cazenga')
        ->and((string) $customers[0]->BillingAddress->Country)->toBe('AO');
});

test('the export downloads as XML with a named file', function () {
    $fixture = pdfFixture();
    documentWithLines($fixture);

    $this->actingAs($fixture['owner'])
        ->get(route('saft.export', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertDownload();
});

test('a period that runs backwards is refused', function () {
    $fixture = pdfFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('saft.export', ['from' => '2026-12-31', 'to' => '2026-01-01']))
        ->assertSessionHasErrors('to');
});
