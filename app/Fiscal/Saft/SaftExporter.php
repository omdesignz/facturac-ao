<?php

namespace App\Fiscal\Saft;

use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtConnection;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\PaymentMethod;
use App\QuoteStatus;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use XMLWriter;

/**
 * Version 1.01_01 SAF-T (AO) billing export.
 *
 * The writer follows the XSD sequence exactly. It deliberately covers only
 * ledgers owned by this product: sales documents, goods movements, quotes and
 * receipts. General-ledger SAF-T needs accounting data and is a separate file.
 */
final class SaftExporter
{
    private const TEXT_LIMITS = [
        'CompanyName' => 200,
        'BusinessName' => 60,
        'AddressDetail' => 250,
        'City' => 50,
        'Province' => 50,
        'CustomerID' => 30,
        'SupplierID' => 30,
        'AccountID' => 30,
        'CustomerTaxID' => 30,
        'SupplierTaxID' => 30,
        'ProductCode' => 60,
        'ProductDescription' => 200,
        'ProductNumberCode' => 60,
        'TaxEntity' => 20,
        'ProductCompanyTaxID' => 20,
        'SourceID' => 30,
        'UnitOfMeasure' => 20,
        'Description' => 200,
        'MovementComments' => 60,
        'TaxExemptionReason' => 60,
        'OriginatingON' => 60,
        'Reason' => 50,
    ];

    private XMLWriter $writer;

    public function __construct(private FiscalCalculator $calculator) {}

    public function export(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment = null,
    ): string {
        $this->writer = new XMLWriter;
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');
        $this->writer->startElement('AuditFile');
        $this->writer->writeAttribute('xmlns', (string) config('fiscal.saft.namespace'));
        $this->writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $fiscalDocuments = $this->fiscalDocuments($legalEntity, $from, $to, $establishment);
        $salesInvoices = $fiscalDocuments
            ->reject(fn (FiscalDocument $document): bool => $document->document_type->settlesOtherDocuments())
            ->values();
        $payments = $fiscalDocuments
            ->filter(fn (FiscalDocument $document): bool => $document->document_type->settlesOtherDocuments())
            ->values();
        $movements = $this->transportDocuments($legalEntity, $from, $to, $establishment);
        $workingDocuments = $this->quotes($legalEntity, $from, $to, $establishment);

        $this->header($legalEntity, $from, $to, $establishment, $fiscalDocuments, $movements);
        $this->masterFiles(
            $legalEntity,
            $salesInvoices,
            $payments,
            $movements,
            $workingDocuments,
        );
        $this->sourceDocuments($salesInvoices, $movements, $workingDocuments, $payments);

        $this->writer->endElement();
        $this->writer->endDocument();

        return $this->writer->outputMemory();
    }

    public function filename(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment = null,
    ): string {
        $scope = $establishment instanceof Establishment
            ? '_'.Str::upper($establishment->code)
            : '';

        return sprintf(
            'SAFT_AO_%s%s_%s_%s.xml',
            $legalEntity->tax_identification_number,
            $scope,
            $from->format('Ymd'),
            $to->format('Ymd'),
        );
    }

    /** @return Collection<int, FiscalDocument> */
    private function fiscalDocuments(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment,
    ): Collection {
        return FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('document_no')
            ->whereBetween('document_date', [$from->toDateString(), $to->toDateString()])
            ->with([
                'lines.taxes',
                'customer',
                'establishment',
                'withholdings',
                'settlements.settledDocument',
            ])
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, TransportDocument> */
    private function transportDocuments(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment,
    ): Collection {
        return TransportDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('document_no')
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->with(['lines', 'customer', 'establishment'])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Quote> */
    private function quotes(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment,
    ): Collection {
        return Quote::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $establishment instanceof Establishment,
                fn ($query) => $query->where('establishment_id', $establishment->id),
            )
            ->whereNotNull('sent_at')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->with(['lines', 'customer', 'establishment'])
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $fiscalDocuments
     * @param  Collection<int, TransportDocument>  $movements
     */
    private function header(
        LegalEntity $legalEntity,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Establishment $establishment,
        Collection $fiscalDocuments,
        Collection $movements,
    ): void {
        $address = $establishment
            ?? $legalEntity->establishments()->where('is_head_office', true)->first()
            ?? $legalEntity->establishments()->first();
        $validationNumber = $this->softwareValidationNumber($legalEntity, $fiscalDocuments, $movements);

        $this->writer->startElement('Header');
        $this->write('AuditFileVersion', (string) config('fiscal.saft.version'));
        $this->write('CompanyID', $this->required($legalEntity->tax_identification_number));
        $this->write('TaxRegistrationNumber', $this->required($legalEntity->tax_identification_number));
        $this->write('TaxAccountingBasis', 'F');
        $this->write('CompanyName', $this->required($legalEntity->legal_name));
        $this->write('BusinessName', $this->required($legalEntity->trade_name ?? $legalEntity->legal_name));

        $this->writer->startElement('CompanyAddress');
        $this->write('AddressDetail', $this->required($address?->address_line));
        $this->write('City', $this->required($address?->municipality));
        if (filled($address?->province_code)) {
            $this->write('Province', (string) $address?->province_code);
        }
        $this->write('Country', (string) config('fiscal.saft.tax_country_region'));
        $this->writer->endElement();

        $this->write('FiscalYear', (string) $from->year);
        $this->write('StartDate', $from->toDateString());
        $this->write('EndDate', $to->toDateString());
        $this->write('CurrencyCode', (string) config('fiscal.saft.currency_code'));
        $this->write('DateCreated', now('Africa/Luanda')->toDateString());
        $this->write('TaxEntity', $establishment?->code ?? 'Global');
        $this->write(
            'ProductCompanyTaxID',
            $this->required(config('agt.software.company_tax_id'), '0'),
        );
        $this->write('SoftwareValidationNumber', $validationNumber);
        $this->write('ProductID', $this->productId());
        $this->write('ProductVersion', (string) config('app.version', '1.0'));
        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $salesInvoices
     * @param  Collection<int, FiscalDocument>  $payments
     * @param  Collection<int, TransportDocument>  $movements
     * @param  Collection<int, Quote>  $workingDocuments
     */
    private function masterFiles(
        LegalEntity $legalEntity,
        Collection $salesInvoices,
        Collection $payments,
        Collection $movements,
        Collection $workingDocuments,
    ): void {
        $this->writer->startElement('MasterFiles');

        foreach ($this->customers(
            $legalEntity,
            $salesInvoices,
            $payments,
            $movements,
            $workingDocuments,
        ) as $customer) {
            $this->customer($customer);
        }

        foreach ($this->suppliers($movements) as $supplier) {
            $this->supplier($supplier);
        }

        foreach ($this->products(
            $legalEntity,
            $salesInvoices,
            $movements,
            $workingDocuments,
        ) as $product) {
            $this->product($product);
        }

        $this->taxTable($salesInvoices, $workingDocuments);
        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $salesInvoices
     * @param  Collection<int, FiscalDocument>  $payments
     * @param  Collection<int, TransportDocument>  $movements
     * @param  Collection<int, Quote>  $workingDocuments
     * @return array<string, array{id: string, tax_id: string, name: string, address: string, city: string, country: string}>
     */
    private function customers(
        LegalEntity $legalEntity,
        Collection $salesInvoices,
        Collection $payments,
        Collection $movements,
        Collection $workingDocuments,
    ): array {
        $customers = [];

        foreach ($legalEntity->customers()->orderBy('id')->get() as $customer) {
            $customers[$customer->public_id] = $this->customerFromModel($customer);
        }

        foreach ($salesInvoices->concat($payments) as $document) {
            if ($document->customer instanceof Customer) {
                continue;
            }

            $id = $this->fiscalCustomerId($document);
            $customers[$id] = [
                'id' => $id,
                'tax_id' => $this->taxId($document->customer_tax_identification_number),
                'name' => $this->required($document->customer_name, 'Consumidor final'),
                'address' => $this->required($document->customer_address),
                'city' => 'Desconhecido',
                'country' => $document->customer_country_code ?: 'AO',
            ];
        }

        foreach ($movements as $document) {
            if ($document->document_type === TransportDocumentType::ReturnNote) {
                continue;
            }

            if ($document->customer instanceof Customer) {
                continue;
            }

            $id = $this->transportCustomerId($document);
            $customers[$id] = [
                'id' => $id,
                'tax_id' => $this->taxId($document->recipient_tax_identification_number),
                'name' => $this->required($document->recipient_name),
                'address' => $this->required($document->recipient_address),
                'city' => $this->required($document->recipient_city),
                'country' => $document->recipient_country_code ?: 'AO',
            ];
        }

        foreach ($workingDocuments as $quote) {
            if ($quote->customer instanceof Customer) {
                continue;
            }

            $id = $this->quoteCustomerId($quote);
            $customers[$id] = [
                'id' => $id,
                'tax_id' => $this->taxId($quote->customer_tax_identification_number),
                'name' => $this->required($quote->customer_name, 'Consumidor final'),
                'address' => $this->required($quote->customer_address),
                'city' => 'Desconhecido',
                'country' => $quote->customer_country_code ?: 'AO',
            ];
        }

        return $customers;
    }

    /**
     * @param  Collection<int, TransportDocument>  $movements
     * @return array<string, array{id: string, tax_id: string, name: string, address: string, city: string, country: string}>
     */
    private function suppliers(Collection $movements): array
    {
        $suppliers = [];

        foreach ($movements as $document) {
            if ($document->document_type !== TransportDocumentType::ReturnNote) {
                continue;
            }

            $id = $this->transportSupplierId($document);
            $suppliers[$id] = [
                'id' => $id,
                'tax_id' => $this->taxId($document->recipient_tax_identification_number),
                'name' => $this->required($document->recipient_name),
                'address' => $this->required($document->recipient_address),
                'city' => $this->required($document->recipient_city),
                'country' => $document->recipient_country_code ?: 'AO',
            ];
        }

        return $suppliers;
    }

    /** @return array{id: string, tax_id: string, name: string, address: string, city: string, country: string} */
    private function customerFromModel(Customer $customer): array
    {
        return [
            'id' => $customer->public_id,
            'tax_id' => $this->taxId($customer->tax_identification_number),
            'name' => $this->required($customer->name),
            'address' => $this->required($customer->address_line),
            'city' => 'Desconhecido',
            'country' => $customer->country_code ?: 'AO',
        ];
    }

    /** @param array{id: string, tax_id: string, name: string, address: string, city: string, country: string} $customer */
    private function customer(array $customer): void
    {
        $this->writer->startElement('Customer');
        $this->write('CustomerID', $customer['id']);
        $this->write('AccountID', 'Desconhecido');
        $this->write('CustomerTaxID', $customer['tax_id']);
        $this->write('CompanyName', $customer['name']);
        $this->writer->startElement('BillingAddress');
        $this->write('AddressDetail', $customer['address']);
        $this->write('City', $customer['city']);
        $this->write('Country', $customer['country']);
        $this->writer->endElement();
        $this->write('SelfBillingIndicator', '0');
        $this->writer->endElement();
    }

    /** @param array{id: string, tax_id: string, name: string, address: string, city: string, country: string} $supplier */
    private function supplier(array $supplier): void
    {
        $this->writer->startElement('Supplier');
        $this->write('SupplierID', $supplier['id']);
        $this->write('AccountID', 'Desconhecido');
        $this->write('SupplierTaxID', $supplier['tax_id']);
        $this->write('CompanyName', $supplier['name']);
        $this->writer->startElement('BillingAddress');
        $this->write('AddressDetail', $supplier['address']);
        $this->write('City', $supplier['city']);
        $this->write('Country', $supplier['country']);
        $this->writer->endElement();
        $this->write('SelfBillingIndicator', '0');
        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $salesInvoices
     * @param  Collection<int, TransportDocument>  $movements
     * @param  Collection<int, Quote>  $workingDocuments
     * @return array<string, array{type: string, code: string, description: string}>
     */
    private function products(
        LegalEntity $legalEntity,
        Collection $salesInvoices,
        Collection $movements,
        Collection $workingDocuments,
    ): array {
        $products = [];

        foreach ($legalEntity->catalogueItems()->orderBy('id')->get() as $item) {
            $products[$item->code] = [
                'type' => $item->tracks_stock ? 'P' : 'S',
                'code' => $item->code,
                'description' => $item->name,
            ];
        }

        foreach ($salesInvoices as $document) {
            foreach ($document->lines as $line) {
                $products[$line->product_code] ??= [
                    'type' => 'O',
                    'code' => $line->product_code,
                    'description' => $line->product_description,
                ];
            }
        }

        foreach ($movements as $document) {
            foreach ($document->lines as $line) {
                $products[$line->product_code] ??= [
                    'type' => 'P',
                    'code' => $line->product_code,
                    'description' => $line->product_description,
                ];
            }
        }

        foreach ($workingDocuments as $quote) {
            foreach ($quote->lines as $line) {
                $code = $this->quoteProductCode($quote, $line);
                $products[$code] ??= [
                    'type' => 'O',
                    'code' => $code,
                    'description' => $line->product_description,
                ];
            }
        }

        return $products;
    }

    /** @param array{type: string, code: string, description: string} $product */
    private function product(array $product): void
    {
        $this->writer->startElement('Product');
        $this->write('ProductType', $product['type']);
        $this->write('ProductCode', $product['code']);
        $this->write('ProductDescription', $product['description']);
        $this->write('ProductNumberCode', $product['code']);
        $this->writer->endElement();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $salesInvoices
     * @param  Collection<int, Quote>  $workingDocuments
     */
    private function taxTable(Collection $salesInvoices, Collection $workingDocuments): void
    {
        $entries = [];

        foreach ($salesInvoices as $document) {
            foreach ($document->lines as $line) {
                foreach ($line->taxes as $tax) {
                    $type = $this->saftTaxType($tax->tax_type->value);
                    $code = $tax->tax_code ?? ($type === 'NS' ? 'NS' : 'NOR');
                    $key = "{$type}|{$code}|{$tax->tax_rate_basis_points}";
                    $entries[$key] = [
                        'type' => $type,
                        'code' => $code,
                        'rate' => $tax->tax_rate_basis_points,
                    ];
                }
            }
        }

        foreach ($workingDocuments as $quote) {
            foreach ($quote->lines as $line) {
                $type = $this->saftTaxType($line->tax_type);
                $code = $line->tax_code ?? ($type === 'NS' ? 'NS' : 'NOR');
                $rate = $this->decimalToScaled((string) $line->tax_percentage, 2);
                $entries["{$type}|{$code}|{$rate}"] = [
                    'type' => $type,
                    'code' => $code,
                    'rate' => $rate,
                ];
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
     * @param  Collection<int, FiscalDocument>  $salesInvoices
     * @param  Collection<int, TransportDocument>  $movements
     * @param  Collection<int, Quote>  $workingDocuments
     * @param  Collection<int, FiscalDocument>  $payments
     */
    private function sourceDocuments(
        Collection $salesInvoices,
        Collection $movements,
        Collection $workingDocuments,
        Collection $payments,
    ): void {
        $this->writer->startElement('SourceDocuments');
        $this->salesInvoices($salesInvoices);
        $this->movementOfGoods($movements);
        $this->workingDocuments($workingDocuments);
        $this->payments($payments);
        $this->writer->endElement();
    }

    /** @param Collection<int, FiscalDocument> $documents */
    private function salesInvoices(Collection $documents): void
    {
        $this->writer->startElement('SalesInvoices');
        $this->write('NumberOfEntries', (string) $documents->count());
        $this->write('TotalDebit', $this->decimal($this->debitTotal($documents), 2));
        $this->write('TotalCredit', $this->decimal($this->creditTotal($documents), 2));
        foreach ($documents as $document) {
            $this->invoice($document);
        }
        $this->writer->endElement();
    }

    private function invoice(FiscalDocument $document): void
    {
        $this->writer->startElement('Invoice');
        $this->write('InvoiceNo', (string) $document->document_no);
        $this->writer->startElement('DocumentStatus');
        $this->write('InvoiceStatus', $this->fiscalStatus($document));
        $this->write('InvoiceStatusDate', $this->dateTime(
            $document->issued_at ?? $document->created_at,
        ));
        if ($document->status === FiscalDocumentStatus::Invalid) {
            $this->write('Reason', 'Documento anulado');
        }
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        $this->write('SourceBilling', 'P');
        $this->writer->endElement();
        $this->write('Hash', $document->document_payload_sha256 ?: '0');
        $this->write('HashControl', $this->validSoftwareValidationNumber(
            $document->software_validation_number,
        ) ? '1' : '0');
        $this->write('Period', (string) $document->document_date->month);
        $this->write('InvoiceDate', $document->document_date->toDateString());
        $this->write('InvoiceType', $document->document_type->value);
        $this->writer->startElement('SpecialRegimes');
        $this->write('SelfBillingIndicator', '0');
        $this->write('CashVATSchemeIndicator', '0');
        $this->write('ThirdPartiesBillingIndicator', '0');
        $this->writer->endElement();
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        $this->write('SystemEntryDate', $this->dateTime(
            $document->system_entry_at ?? $document->created_at,
        ));
        $this->write('CustomerID', $this->fiscalCustomerId($document));

        foreach ($document->lines as $line) {
            $this->invoiceLine($document, $line);
        }

        $this->writer->startElement('DocumentTotals');
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

        foreach ($document->withholdings as $withholding) {
            $this->writer->startElement('WithholdingTax');
            $this->write('WithholdingTaxType', $withholding->withholding_type->value);
            $this->write('WithholdingTaxDescription', $withholding->withholding_type->taxLabel());
            $this->write('WithholdingTaxAmount', $this->inBaseCurrency($document, $withholding->amount_minor));
            $this->writer->endElement();
        }
        $this->writer->endElement();
    }

    private function invoiceLine(FiscalDocument $document, FiscalDocumentLine $line): void
    {
        $this->writer->startElement('Line');
        $this->write('LineNumber', (string) $line->line_number);
        $this->write('ProductCode', $line->product_code);
        $this->write('ProductDescription', $line->product_description);
        $this->write('Quantity', $this->decimal($line->quantity_units, $line->quantity_scale));
        $this->write('UnitOfMeasure', $line->unit_of_measure);
        $this->write('UnitPrice', $this->inBaseCurrency($document, $line->unit_price_base_minor));
        $this->write('TaxPointDate', ($line->operation_date ?? $document->document_date)->toDateString());
        if ($document->document_type->isAdjustment() && filled($document->references_document_no)) {
            $this->writer->startElement('References');
            $this->write('Reference', (string) $document->references_document_no);
            if (filled($document->adjustment_reason)) {
                $this->write('Reason', Str::limit((string) $document->adjustment_reason, 50, ''));
            }
            $this->writer->endElement();
        }
        $this->write('Description', $line->product_description);
        $this->write(
            $document->document_type === FiscalDocumentType::CreditNote
                ? 'CreditAmount'
                : 'DebitAmount',
            $this->inBaseCurrency($document, $line->net_amount_minor),
        );

        $tax = $line->taxes->sortBy(fn ($tax): int => match ($tax->tax_type->value) {
            'IVA' => 0,
            'IS' => 1,
            'NS' => 2,
            default => 3,
        })->first();
        $taxType = $tax === null ? 'NS' : $this->saftTaxType($tax->tax_type->value);
        $taxCode = $tax?->tax_code ?? ($taxType === 'NS' ? 'NS' : 'NOR');
        $taxRate = $tax?->tax_rate_basis_points ?? 0;
        $this->writer->startElement('Tax');
        $this->write('TaxType', $taxType);
        $this->write('TaxCountryRegion', (string) config('fiscal.saft.tax_country_region'));
        $this->write('TaxCode', $taxCode);
        $this->write('TaxPercentage', $this->decimal($taxRate, 2));
        $this->writer->endElement();

        $exemptionCode = $tax?->tax_exemption_code;
        if ($taxRate === 0 || filled($exemptionCode)) {
            $code = $exemptionCode ?: 'M02';
            $this->write('TaxExemptionReason', $this->exemptionReason($code));
            $this->write('TaxExemptionCode', $code);
        }

        $discount = max(0, $line->base_amount_minor - $line->net_amount_minor);
        if ($discount > 0) {
            $this->write('SettlementAmount', $this->inBaseCurrency($document, $discount));
        }
        $this->writer->endElement();
    }

    /** @param Collection<int, TransportDocument> $documents */
    private function movementOfGoods(Collection $documents): void
    {
        $this->writer->startElement('MovementOfGoods');
        $this->write('NumberOfMovementLines', (string) $documents->sum(
            fn (TransportDocument $document): int => $document->lines->count(),
        ));
        $quantity = $documents->sum(fn (TransportDocument $document): int => $document->lines->sum(
            fn (TransportDocumentLine $line): int => $this->rescale($line->quantity_units, $line->quantity_scale, 3),
        ));
        $this->write('TotalQuantityIssued', $this->decimal($quantity, 3));
        foreach ($documents as $document) {
            $this->stockMovement($document);
        }
        $this->writer->endElement();
    }

    private function stockMovement(TransportDocument $document): void
    {
        $this->writer->startElement('StockMovement');
        $this->write('DocumentNumber', (string) $document->document_no);
        $this->writer->startElement('DocumentStatus');
        $this->write('MovementStatus', $document->status->movementStatus());
        $this->write('MovementStatusDate', $this->dateTime(
            $document->cancelled_at ?? $document->issued_at ?? $document->created_at,
        ));
        if ($document->status === TransportDocumentStatus::Cancelled) {
            $this->write('Reason', Str::limit($document->cancellation_reason ?? 'Documento anulado', 50, ''));
        }
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        $this->write('SourceBilling', 'P');
        $this->writer->endElement();
        $this->write('Hash', $document->document_hash ?: '0');
        $this->write('HashControl', $this->validSoftwareValidationNumber(
            $document->software_validation_number,
        ) ? ($document->hash_control ?: '1') : '0');
        $this->write('Period', (string) $document->movement_date->month);
        $this->write('MovementDate', $document->movement_date->toDateString());
        $this->write('MovementType', $document->document_type->value);
        $this->write('SystemEntryDate', $this->dateTime(
            $document->system_entry_at ?? $document->created_at,
        ));
        if ($document->document_type === TransportDocumentType::ReturnNote) {
            $this->write('SupplierID', $this->transportSupplierId($document));
        } else {
            $this->write('CustomerID', $this->transportCustomerId($document));
        }
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        if (filled($document->notes)) {
            $this->write('MovementComments', Str::limit((string) $document->notes, 60, ''));
        }
        $this->shippingPoint('ShipTo', $document->destination_address, $document->destination_city, $document->destination_province, $document->destination_country_code, $document->movement_date->toDateString());
        $this->shippingPoint('ShipFrom', $document->origin_address, $document->origin_city, $document->origin_province, $document->origin_country_code, $document->movement_date->toDateString());
        if ($document->movement_end_at !== null) {
            $this->write('MovementEndTime', $this->dateTime($document->movement_end_at));
        }
        $this->write('MovementStartTime', $this->dateTime($document->movement_start_at));

        foreach ($document->lines as $line) {
            $this->transportLine($line);
        }

        $this->writer->startElement('DocumentTotals');
        $this->write('TaxPayable', '0.00');
        $this->write('NetTotal', $this->decimal($document->net_total_minor, 2));
        $this->write('GrossTotal', $this->decimal($document->gross_total_minor, 2));
        $this->writer->endElement();
        $this->writer->endElement();
    }

    private function transportLine(TransportDocumentLine $line): void
    {
        $this->writer->startElement('Line');
        $this->write('LineNumber', (string) $line->line_number);
        $this->write('ProductCode', $line->product_code);
        $this->write('ProductDescription', $line->product_description);
        $this->write('Quantity', $this->decimal($line->quantity_units, $line->quantity_scale));
        $this->write('UnitOfMeasure', $line->unit_of_measure);
        $this->write('UnitPrice', $this->decimal($line->unit_price_minor, 2));
        $this->write('Description', $line->product_description);
        $this->write('DebitAmount', $this->decimal($line->net_amount_minor, 2));
        $this->writer->endElement();
    }

    private function shippingPoint(
        string $element,
        string $address,
        string $city,
        ?string $province,
        string $country,
        string $deliveryDate,
    ): void {
        $this->writer->startElement($element);
        $this->write('DeliveryDate', $deliveryDate);
        $this->writer->startElement('Address');
        $this->write('AddressDetail', $this->required($address));
        $this->write('City', $this->required($city));
        if (filled($province)) {
            $this->write('Province', (string) $province);
        }
        $this->write('Country', $country ?: 'AO');
        $this->writer->endElement();
        $this->writer->endElement();
    }

    /** @param Collection<int, Quote> $quotes */
    private function workingDocuments(Collection $quotes): void
    {
        $this->writer->startElement('WorkingDocuments');
        $this->write('NumberOfEntries', (string) $quotes->count());
        $debit = $quotes
            ->reject(fn (Quote $quote): bool => in_array($quote->status, [
                QuoteStatus::Converted,
                QuoteStatus::Rejected,
                QuoteStatus::Expired,
            ], true))
            ->sum(fn (Quote $quote): int => $quote->net_total_minor);
        $this->write('TotalDebit', $this->decimal($debit, 2));
        $this->write('TotalCredit', '0.00');
        foreach ($quotes as $quote) {
            $this->workDocument($quote);
        }
        $this->writer->endElement();
    }

    private function workDocument(Quote $quote): void
    {
        $this->writer->startElement('WorkDocument');
        $this->write('DocumentNumber', $quote->reference);
        $this->writer->startElement('DocumentStatus');
        $this->write('WorkStatus', $this->workingStatus($quote));
        $this->write('WorkStatusDate', $this->dateTime(
            $quote->decided_at ?? $quote->sent_at ?? $quote->updated_at,
        ));
        if (in_array($quote->status, [QuoteStatus::Rejected, QuoteStatus::Expired], true)) {
            $this->write('Reason', $quote->status === QuoteStatus::Rejected
                ? 'Orçamento recusado pelo cliente'
                : 'Prazo de validade expirado');
        }
        $this->write('SourceID', $this->sourceId($quote->created_by_user_id));
        $this->write('SourceBilling', 'P');
        $this->writer->endElement();
        $this->write('Hash', $this->quoteHash($quote));
        $this->write('HashControl', $this->legalEntityHasValidation($quote->legal_entity_id) ? '1' : '0');
        $this->write('Period', (string) $quote->issue_date->month);
        $this->write('WorkDate', $quote->issue_date->toDateString());
        $this->write('WorkType', 'OR');
        $this->write('SourceID', $this->sourceId($quote->created_by_user_id));
        $this->write('SystemEntryDate', $this->dateTime($quote->sent_at ?? $quote->created_at));
        $this->write('CustomerID', $this->quoteCustomerId($quote));

        foreach ($quote->lines as $line) {
            $this->quoteLine($quote, $line);
        }

        $this->writer->startElement('DocumentTotals');
        $this->write('TaxPayable', $this->decimal($quote->tax_total_minor, 2));
        $this->write('NetTotal', $this->decimal($quote->net_total_minor, 2));
        $this->write('GrossTotal', $this->decimal($quote->gross_total_minor, 2));
        $this->writer->endElement();
        $this->writer->endElement();
    }

    private function quoteLine(Quote $quote, QuoteLine $line): void
    {
        $this->writer->startElement('Line');
        $this->write('LineNumber', (string) $line->line_number);
        $this->write('ProductCode', $this->quoteProductCode($quote, $line));
        $this->write('ProductDescription', $line->product_description);
        $this->write('Quantity', $this->decimal($line->quantity_units, $line->quantity_scale));
        $this->write('UnitOfMeasure', $line->unit_of_measure);
        $this->write('UnitPrice', $this->decimal($line->unit_price_minor, 2));
        $this->write('TaxPointDate', $quote->issue_date->toDateString());
        $this->write('Description', $line->product_description);
        $this->write('DebitAmount', $this->decimal($line->net_amount_minor, 2));
        $taxType = $this->saftTaxType($line->tax_type);
        $taxCode = $line->tax_code ?? ($taxType === 'NS' ? 'NS' : 'NOR');
        $taxRate = $this->decimalToScaled((string) $line->tax_percentage, 2);
        $this->writer->startElement('Tax');
        $this->write('TaxType', $taxType);
        $this->write('TaxCountryRegion', (string) config('fiscal.saft.tax_country_region'));
        $this->write('TaxCode', $taxCode);
        $this->write('TaxPercentage', $this->decimal($taxRate, 2));
        $this->writer->endElement();
        if ($taxRate === 0 || filled($line->tax_exemption_code)) {
            $code = $line->tax_exemption_code ?: 'M02';
            $this->write('TaxExemptionReason', $this->exemptionReason($code));
            $this->write('TaxExemptionCode', $code);
        }
        $base = intdiv($line->quantity_units * $line->unit_price_minor, 10 ** $line->quantity_scale);
        $discount = max(0, $base - $line->net_amount_minor);
        if ($discount > 0) {
            $this->write('SettlementAmount', $this->decimal($discount, 2));
        }
        $this->writer->endElement();
    }

    /** @param Collection<int, FiscalDocument> $documents */
    private function payments(Collection $documents): void
    {
        $this->writer->startElement('Payments');
        $this->write('NumberOfEntries', (string) $documents->count());
        $credit = $documents
            ->reject(fn (FiscalDocument $document): bool => $document->status === FiscalDocumentStatus::Invalid)
            ->sum(fn (FiscalDocument $document): int => $this->calculator->convertedAmount(
                $document->payment_amount_minor ?? $document->gross_total_minor,
                $document->exchange_rate_micro,
            ));
        $this->write('TotalDebit', '0.00');
        $this->write('TotalCredit', $this->decimal($credit, 2));
        foreach ($documents as $document) {
            $this->payment($document);
        }
        $this->writer->endElement();
    }

    private function payment(FiscalDocument $document): void
    {
        $amount = $document->payment_amount_minor ?? $document->gross_total_minor;
        $this->writer->startElement('Payment');
        $this->write('PaymentRefNo', (string) $document->document_no);
        $this->write('Period', (string) $document->document_date->month);
        $this->write('TransactionDate', ($document->payment_date ?? $document->document_date)->toDateString());
        $this->write('PaymentType', $this->paymentType($document->document_type));
        if (filled($document->notes)) {
            $this->write('Description', Str::limit((string) $document->notes, 200, ''));
        }
        $this->writer->startElement('DocumentStatus');
        $this->write('PaymentStatus', $this->fiscalStatus($document));
        $this->write('PaymentStatusDate', $this->dateTime(
            $document->issued_at ?? $document->created_at,
        ));
        if ($document->status === FiscalDocumentStatus::Invalid) {
            $this->write('Reason', 'Documento anulado');
        }
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        $this->write('SourcePayment', 'P');
        $this->writer->endElement();
        $this->writer->startElement('PaymentMethod');
        $this->write('PaymentMechanism', $this->paymentMechanism($document->payment_method));
        $this->write('PaymentAmount', $this->inBaseCurrency($document, $amount));
        $this->write('PaymentDate', ($document->payment_date ?? $document->document_date)->toDateString());
        $this->writer->endElement();
        $this->write('SourceID', $this->sourceId($document->issued_by_user_id ?? $document->created_by_user_id));
        $this->write('SystemEntryDate', $this->dateTime(
            $document->system_entry_at ?? $document->created_at,
        ));
        $this->write('CustomerID', $this->fiscalCustomerId($document));

        foreach ($document->settlements as $index => $settlement) {
            $this->writer->startElement('Line');
            $this->write('LineNumber', (string) ($index + 1));
            $this->writer->startElement('SourceDocumentID');
            $this->write('OriginatingON', $settlement->settled_document_no);
            $this->write(
                'InvoiceDate',
                $settlement->settledDocument?->document_date?->toDateString()
                    ?? $document->document_date->toDateString(),
            );
            $this->writer->endElement();
            $this->write('CreditAmount', $this->inBaseCurrency($document, $settlement->amount_minor));
            $this->writer->endElement();
        }

        $this->writer->startElement('DocumentTotals');
        $this->write('TaxPayable', '0.00');
        $this->write('NetTotal', $this->inBaseCurrency($document, $amount));
        $this->write('GrossTotal', $this->inBaseCurrency($document, $amount));
        if ($document->isForeignCurrency()) {
            $this->writer->startElement('Currency');
            $this->write('CurrencyCode', $document->currency_code);
            $this->write('CurrencyAmount', $this->decimal($amount, 2));
            $this->write('ExchangeRate', $this->decimal($document->exchange_rate_micro, 6));
            $this->writer->endElement();
        }
        $this->writer->endElement();
        $this->writer->endElement();
    }

    /** @param Collection<int, FiscalDocument> $documents */
    private function debitTotal(Collection $documents): int
    {
        return $documents
            ->reject(fn (FiscalDocument $document): bool => $document->document_type === FiscalDocumentType::CreditNote
                || $document->status === FiscalDocumentStatus::Invalid)
            ->sum(fn (FiscalDocument $document): int => $this->calculator->convertedAmount(
                $document->net_total_minor,
                $document->exchange_rate_micro,
            ));
    }

    /** @param Collection<int, FiscalDocument> $documents */
    private function creditTotal(Collection $documents): int
    {
        return $documents
            ->filter(fn (FiscalDocument $document): bool => $document->document_type === FiscalDocumentType::CreditNote
                && $document->status !== FiscalDocumentStatus::Invalid)
            ->sum(fn (FiscalDocument $document): int => $this->calculator->convertedAmount(
                $document->net_total_minor,
                $document->exchange_rate_micro,
            ));
    }

    private function inBaseCurrency(FiscalDocument $document, int $minor): string
    {
        return $this->decimal(
            $this->calculator->convertedAmount($minor, $document->exchange_rate_micro),
            2,
        );
    }

    /**
     * @param  Collection<int, FiscalDocument>  $fiscalDocuments
     * @param  Collection<int, TransportDocument>  $movements
     */
    private function softwareValidationNumber(
        LegalEntity $legalEntity,
        Collection $fiscalDocuments,
        Collection $movements,
    ): string {
        $number = $fiscalDocuments->first(
            fn (FiscalDocument $document): bool => filled($document->software_validation_number),
        )?->software_validation_number
            ?? $movements->first(
                fn (TransportDocument $document): bool => filled($document->software_validation_number),
            )?->software_validation_number
            ?? $legalEntity->agtConnections()
                ->whereNotNull('software_validation_number')
                ->latest('id')
                ->value('software_validation_number');

        return $this->normalisedSoftwareValidationNumber($number);
    }

    private function legalEntityHasValidation(int $legalEntityId): bool
    {
        return $this->validSoftwareValidationNumber(AgtConnection::query()
            ->where('legal_entity_id', $legalEntityId)
            ->whereNotNull('software_validation_number')
            ->value('software_validation_number'));
    }

    private function productId(): string
    {
        $product = $this->required(config('agt.software.product_name'), (string) config('app.name'));
        $company = $this->required(config('agt.software.company_name'), 'VAP SOLUÇÕES, LDA');

        return "{$product}/{$company}";
    }

    private function fiscalCustomerId(FiscalDocument $document): string
    {
        return $document->customer?->public_id ?? 'F'.$document->public_id;
    }

    private function transportCustomerId(TransportDocument $document): string
    {
        return $document->customer?->public_id ?? 'T'.$document->public_id;
    }

    private function transportSupplierId(TransportDocument $document): string
    {
        return 'S'.substr(hash(
            'sha256',
            Str::upper(trim($document->recipient_tax_identification_number)),
        ), 0, 29);
    }

    private function quoteCustomerId(Quote $quote): string
    {
        return $quote->customer?->public_id ?? 'Q'.$quote->public_id;
    }

    private function quoteProductCode(Quote $quote, QuoteLine $line): string
    {
        return filled($line->product_code)
            ? (string) $line->product_code
            : "Q{$quote->id}-{$line->line_number}";
    }

    private function quoteHash(Quote $quote): string
    {
        return hash('sha256', (string) json_encode([
            'reference' => $quote->reference,
            'issue_date' => $quote->issue_date->toDateString(),
            'customer_tax_id' => $quote->customer_tax_identification_number,
            'gross_total_minor' => $quote->gross_total_minor,
            'lines' => $quote->lines->map(fn (QuoteLine $line): array => [
                'number' => $line->line_number,
                'code' => $this->quoteProductCode($quote, $line),
                'quantity' => $line->quantity_units,
                'unit_price_minor' => $line->unit_price_minor,
                'gross_amount_minor' => $line->gross_amount_minor,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function paymentType(FiscalDocumentType $type): string
    {
        return match ($type) {
            FiscalDocumentType::CollectionNoticeReceipt => 'AR',
            FiscalDocumentType::Receipt => 'RG',
            default => 'RC',
        };
    }

    private function paymentMechanism(PaymentMethod|string|null $method): string
    {
        $value = $method instanceof PaymentMethod ? $method->value : (string) $method;

        return match ($value) {
            PaymentMethod::Letter->value => 'CI',
            PaymentMethod::DirectDebit->value => 'TB',
            'CC', 'CD', 'CH', 'CI', 'CO', 'CS', 'DE', 'MB', 'NU', 'OU', 'PR', 'TB' => $value,
            default => 'OU',
        };
    }

    private function fiscalStatus(FiscalDocument $document): string
    {
        return $document->status === FiscalDocumentStatus::Invalid ? 'A' : 'N';
    }

    private function workingStatus(Quote $quote): string
    {
        return match ($quote->status) {
            QuoteStatus::Converted => 'F',
            QuoteStatus::Rejected, QuoteStatus::Expired => 'A',
            default => 'N',
        };
    }

    private function normalisedSoftwareValidationNumber(mixed $number): string
    {
        $value = trim((string) ($number ?? ''));

        return $this->validSoftwareValidationNumber($value) ? $value : '0';
    }

    private function validSoftwareValidationNumber(mixed $number): bool
    {
        return preg_match('/^\\d+\/AGT\/\\d{4}$/', trim((string) ($number ?? ''))) === 1;
    }

    private function saftTaxType(string $type): string
    {
        return in_array($type, ['IVA', 'IS', 'NS'], true) ? $type : 'IS';
    }

    private function exemptionReason(string $code): string
    {
        return match ($code) {
            'M00' => 'Regime simplificado de tributação',
            'M02' => 'Transmissão de bens ou serviço não sujeita',
            'M04' => 'Isento nos termos do Código do IVA',
            default => 'Isenção nos termos legais aplicáveis',
        };
    }

    private function taxId(?string $taxId): string
    {
        return filled($taxId) ? trim((string) $taxId) : '999999999';
    }

    private function sourceId(int|string|null $sourceId): string
    {
        return filled($sourceId) ? (string) $sourceId : '0';
    }

    private function required(mixed $value, string $fallback = 'Desconhecido'): string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? $fallback : $trimmed;
    }

    private function write(string $name, string $value): void
    {
        $limit = self::TEXT_LIMITS[$name] ?? null;

        if ($limit !== null) {
            $value = Str::limit($value, $limit, '');
        }

        $this->writer->writeElement($name, $value);
    }

    private function dateTime(mixed $value): string
    {
        return CarbonImmutable::parse($value)->format('Y-m-d\TH:i:s');
    }

    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }

    private function decimalToScaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }

    private function rescale(int $value, int $fromScale, int $toScale): int
    {
        if ($fromScale === $toScale) {
            return $value;
        }

        return $fromScale < $toScale
            ? $value * (10 ** ($toScale - $fromScale))
            : intdiv($value, 10 ** ($fromScale - $toScale));
    }
}
