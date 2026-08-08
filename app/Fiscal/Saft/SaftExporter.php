<?php

namespace App\Fiscal\Saft;

use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use XMLWriter;

/**
 * The SAF-T (AO) audit file.
 *
 * Written straight out with XMLWriter rather than built as a DOM: the file is
 * one pass over the period's documents and can run to hundreds of megabytes
 * for a busy year, which is not something to hold in memory as a tree.
 *
 * Element order matters. The schema is sequenced, not a free bag of elements,
 * so the order here is the order the XSD declares — moving one for tidiness
 * would produce a file the AGT's validator rejects.
 */
class SaftExporter
{
    private XMLWriter $writer;

    public function __construct(private FiscalCalculator $calculator) {}

    /**
     * @return string the SAF-T XML for the period
     */
    public function export(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): string {
        $this->writer = new XMLWriter;
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');

        $this->writer->startElement('AuditFile');
        $this->writer->writeAttribute('xmlns', (string) config('fiscal.saft.namespace'));
        $this->writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $documents = $this->documents($legalEntity, $from, $to);

        $this->header($legalEntity, $from, $to);
        $this->masterFiles($legalEntity, $documents);
        $this->sourceDocuments($documents);

        $this->writer->endElement();
        $this->writer->endDocument();

        return $this->writer->outputMemory();
    }

    public function filename(LegalEntity $legalEntity, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return sprintf(
            'SAFT_AO_%s_%s_%s.xml',
            $legalEntity->tax_identification_number,
            $from->format('Ymd'),
            $to->format('Ymd'),
        );
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    private function documents(LegalEntity $legalEntity, CarbonImmutable $from, CarbonImmutable $to)
    {
        return FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNotNull('document_no')
            ->whereBetween('document_date', [$from->toDateString(), $to->toDateString()])
            ->with(['lines.taxes', 'customer', 'establishment', 'withholdings'])
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();
    }

    private function header(LegalEntity $legalEntity, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $establishment = $legalEntity->establishments()
            ->where('is_head_office', true)
            ->first()
            ?? $legalEntity->establishments()->first();

        $this->writer->startElement('Header');
        $this->write('AuditFileVersion', (string) config('fiscal.saft.version'));
        $this->write('CompanyID', $legalEntity->tax_identification_number);
        $this->write('TaxRegistrationNumber', $legalEntity->tax_identification_number);
        $this->write('TaxAccountingBasis', 'F');
        $this->write('CompanyName', $legalEntity->legal_name);
        $this->write('BusinessName', $legalEntity->trade_name ?? $legalEntity->legal_name);

        $this->writer->startElement('CompanyAddress');
        $this->write('AddressDetail', $establishment->address_line ?? '');
        $this->write('City', $establishment->municipality ?? '');
        $this->write('Province', $establishment->province_code ?? '');
        $this->write('Country', (string) config('fiscal.saft.tax_country_region'));
        $this->writer->endElement();

        $this->write('FiscalYear', (string) $from->year);
        $this->write('StartDate', $from->toDateString());
        $this->write('EndDate', $to->toDateString());
        $this->write('CurrencyCode', (string) config('fiscal.saft.currency_code'));
        $this->write('DateCreated', now()->toDateString());
        $this->write('TaxEntity', $establishment->code ?? 'SEDE');
        $this->write('ProductCompanyTaxID', (string) config('agt.software.company_tax_id', ''));
        $this->write('SoftwareValidationNumber', $this->softwareValidationNumber($legalEntity));
        $this->write('ProductID', (string) config('app.name'));
        $this->write('ProductVersion', (string) config('app.version', '1.0'));
        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $documents
     */
    private function masterFiles(LegalEntity $legalEntity, $documents): void
    {
        $this->writer->startElement('MasterFiles');

        foreach ($legalEntity->customers()->orderBy('id')->get() as $customer) {
            $this->customer($customer);
        }

        foreach ($legalEntity->catalogueItems()->orderBy('id')->get() as $item) {
            $this->product($item);
        }

        $this->taxTable($documents);

        $this->writer->endElement();
    }

    private function customer(Customer $customer): void
    {
        $this->writer->startElement('Customer');
        $this->write('CustomerID', $customer->public_id);
        $this->write('AccountID', 'Desconhecido');
        $this->write('CustomerTaxID', $customer->tax_identification_number);
        $this->write('CompanyName', $customer->name);

        $this->writer->startElement('BillingAddress');
        $this->write('AddressDetail', $customer->address_line ?? 'Desconhecido');
        $this->write('City', 'Desconhecido');
        $this->write('Country', $customer->country_code);
        $this->writer->endElement();

        $this->write('SelfBillingIndicator', '0');
        $this->writer->endElement();
    }

    private function product(CatalogueItem $item): void
    {
        $this->writer->startElement('Product');
        // "S" for a service, "P" for goods; the catalogue already knows which.
        $this->write('ProductType', $item->tracks_stock ? 'P' : 'S');
        $this->write('ProductCode', $item->code);
        $this->write('ProductDescription', $item->name);
        $this->write('ProductNumberCode', $item->code);
        $this->writer->endElement();
    }

    /**
     * Every distinct rate the period actually used.
     *
     * Built from the documents rather than from a static list, because the
     * table has to describe what is in this file — a rate declared but never
     * applied is noise, and one applied but undeclared is a validation error.
     *
     * @param  Collection<int, FiscalDocument>  $documents
     */
    private function taxTable($documents): void
    {
        $entries = [];

        foreach ($documents as $document) {
            foreach ($document->lines as $line) {
                foreach ($line->taxes as $tax) {
                    $key = $tax->tax_type->value.'|'.($tax->tax_code ?? 'NOR').'|'.$tax->tax_rate_basis_points;

                    $entries[$key] = [
                        'type' => $tax->tax_type->value,
                        'code' => $tax->tax_code ?? 'NOR',
                        'rate' => $tax->tax_rate_basis_points,
                    ];
                }
            }
        }

        if ($entries === []) {
            return;
        }

        $this->writer->startElement('TaxTable');

        foreach ($entries as $entry) {
            $this->writer->startElement('TaxTableEntry');
            $this->write('TaxType', $entry['type']);
            $this->write('TaxCountryRegion', (string) config('fiscal.saft.tax_country_region'));
            $this->write('TaxCode', $entry['code']);
            $this->write('Description', "{$entry['type']} {$entry['code']}");
            $this->write('TaxPercentage', $this->decimal($entry['rate'], 2));
            $this->writer->endElement();
        }

        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $documents
     */
    private function sourceDocuments($documents): void
    {
        $this->writer->startElement('SourceDocuments');
        $this->writer->startElement('SalesInvoices');

        $net = 0;
        $tax = 0;

        foreach ($documents as $document) {
            $net += $document->net_total_minor;
            $tax += $document->tax_payable_minor;
        }

        $this->write('NumberOfEntries', (string) $documents->count());
        $this->write('TotalDebit', $this->decimal($this->debitTotal($documents), 2));
        $this->write('TotalCredit', $this->decimal($this->creditTotal($documents), 2));

        foreach ($documents as $document) {
            $this->invoice($document);
        }

        $this->writer->endElement();
        $this->writer->endElement();
    }

    private function invoice(FiscalDocument $document): void
    {
        $this->writer->startElement('Invoice');
        $this->write('InvoiceNo', (string) $document->document_no);

        $this->writer->startElement('DocumentStatus');
        // "N" is normal; a document the AGT rejected is "A" — anulado.
        $this->write(
            'InvoiceStatus',
            $document->status === FiscalDocumentStatus::Invalid ? 'A' : 'N',
        );
        $this->write(
            'InvoiceStatusDate',
            ($document->issued_at ?? $document->created_at)->format('Y-m-d\TH:i:s'),
        );
        $this->write('SourceID', (string) $document->issued_by_user_id);
        $this->write('SourceBilling', 'P');
        $this->writer->endElement();

        $this->write('Hash', (string) $document->document_payload_sha256);
        $this->write('HashControl', '1');
        $this->write('Period', (string) $document->document_date->month);
        $this->write('InvoiceDate', $document->document_date->toDateString());
        $this->write('InvoiceType', $document->document_type->value);

        $this->writer->startElement('SpecialRegimes');
        $this->write('SelfBillingIndicator', '0');
        $this->write('CashVATSchemeIndicator', '0');
        $this->write('ThirdPartiesBillingIndicator', '0');
        $this->writer->endElement();

        $this->write('SourceID', (string) $document->issued_by_user_id);
        $this->write(
            'SystemEntryDate',
            ($document->system_entry_at ?? $document->created_at)->format('Y-m-d\TH:i:s'),
        );
        $this->write('CustomerID', $document->customer->public_id ?? 'Desconhecido');

        foreach ($document->lines as $line) {
            $this->line($document, $line);
        }

        $this->writer->startElement('DocumentTotals');
        /*
         * The audit file is in kwanzas whatever the document was written in, so
         * the totals are converted here and the original is preserved in the
         * Currency block below. A document already in kwanzas converts through
         * a rate of exactly one and is unchanged.
         */
        $this->write('TaxPayable', $this->inBaseCurrency($document, $document->tax_payable_minor));
        $this->write('NetTotal', $this->inBaseCurrency($document, $document->net_total_minor));
        $this->write('GrossTotal', $this->inBaseCurrency($document, $document->gross_total_minor));

        if ($document->isForeignCurrency()) {
            $this->writer->startElement('Currency');
            $this->write('CurrencyCode', $document->currency_code);
            $this->write('CurrencyAmount', $this->decimal($document->gross_total_minor, 2));
            $this->write('ExchangeRate', $this->decimal($document->exchange_rate_micro, 6));
            $this->writer->endElement();
        }

        $this->writer->endElement();

        /*
         * A sibling of DocumentTotals rather than a child of it, which is where
         * the Portuguese schema the AO variant derives from puts it. Worth
         * checking against the published AO XSD before a real filing.
         */
        foreach ($document->withholdings as $withholding) {
            $this->writer->startElement('WithholdingTax');
            $this->write('WithholdingTaxType', $withholding->withholding_type->value);
            $this->write('WithholdingTaxDescription', $withholding->withholding_type->taxLabel());
            $this->write(
                'WithholdingTaxAmount',
                $this->inBaseCurrency($document, $withholding->amount_minor),
            );
            $this->writer->endElement();
        }

        $this->writer->endElement();
    }

    /** An amount on this document, restated in kwanzas for the audit file. */
    private function inBaseCurrency(FiscalDocument $document, int $minor): string
    {
        return $this->decimal(
            $this->calculator->convertedAmount($minor, $document->exchange_rate_micro),
            2,
        );
    }

    private function line(FiscalDocument $document, FiscalDocumentLine $line): void
    {
        $this->writer->startElement('Line');
        $this->write('LineNumber', (string) $line->line_number);
        $this->write('ProductCode', (string) $line->product_code);
        $this->write('ProductDescription', $line->product_description);
        $this->write('Quantity', $this->decimal($line->quantity_units, $line->quantity_scale));
        $this->write('UnitOfMeasure', $line->unit_of_measure);
        $this->write('UnitPrice', $this->decimal($line->unit_price_base_minor, 2));
        $this->write('TaxPointDate', $document->document_date->toDateString());

        /*
         * A credit note reduces what is owed, so its amount is a credit; every
         * other type is a debit. Getting this backwards balances the file to
         * the wrong side and the validator will not catch it.
         */
        $this->write(
            $document->document_type === FiscalDocumentType::CreditNote
                ? 'CreditAmount'
                : 'DebitAmount',
            $this->decimal($line->net_amount_minor, 2),
        );

        foreach ($line->taxes as $tax) {
            $this->writer->startElement('Tax');
            $this->write('TaxType', $tax->tax_type->value);
            $this->write('TaxCountryRegion', (string) config('fiscal.saft.tax_country_region'));
            $this->write('TaxCode', $tax->tax_code ?? 'NOR');
            $this->write('TaxPercentage', $this->decimal($tax->tax_rate_basis_points, 2));
            $this->writer->endElement();

            if ($tax->tax_exemption_code !== null) {
                $this->write('TaxExemptionCode', $tax->tax_exemption_code);
            }
        }

        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $documents
     */
    private function debitTotal($documents): int
    {
        return $documents
            ->reject(fn (FiscalDocument $document): bool => $document->document_type === FiscalDocumentType::CreditNote)
            ->sum(fn (FiscalDocument $document): int => $this->calculator->convertedAmount(
                $document->net_total_minor,
                $document->exchange_rate_micro,
            ));
    }

    /**
     * @param  Collection<int, FiscalDocument>  $documents
     */
    private function creditTotal($documents): int
    {
        return $documents
            ->filter(fn (FiscalDocument $document): bool => $document->document_type === FiscalDocumentType::CreditNote)
            ->sum(fn (FiscalDocument $document): int => $this->calculator->convertedAmount(
                $document->net_total_minor,
                $document->exchange_rate_micro,
            ));
    }

    private function softwareValidationNumber(LegalEntity $legalEntity): string
    {
        return (string) FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNotNull('software_validation_number')
            ->value('software_validation_number');
    }

    private function write(string $name, string $value): void
    {
        $this->writer->writeElement($name, $value);
    }

    /** Scaled integers as the plain decimal the schema expects. */
    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }
}
