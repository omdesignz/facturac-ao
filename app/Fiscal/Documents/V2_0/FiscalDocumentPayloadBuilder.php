<?php

namespace App\Fiscal\Documents\V2_0;

use App\Fiscal\Agt\Exceptions\UnsupportedAgtSchema;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalDocumentSettlement;
use App\Models\FiscalDocumentWithholding;
use DomainException;

final class FiscalDocumentPayloadBuilder
{
    public function __construct(private FiscalCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function signableObject(FiscalDocument $document): array
    {
        $this->ensureReadyForPayload($document);

        return [
            'documentNo' => $document->document_no,
            'taxRegistrationNumber' => $document->legalEntity->tax_identification_number,
            'documentType' => $document->document_type->value,
            'documentDate' => $document->document_date->toDateString(),
            'customerTaxID' => $document->customer_tax_identification_number,
            'customerCountry' => $document->customer_country_code,
            'companyName' => $document->customer_name,
            'documentTotals' => $this->documentTotals($document),
        ];
    }

    /** @return array<string, mixed> */
    public function document(FiscalDocument $document): array
    {
        $this->ensureReadyForPayload($document);
        $document->loadMissing([
            'legalEntity',
            'lines.taxes',
            'settlements.settledDocument',
            'withholdings',
        ]);

        return array_filter([
            'documentNo' => $document->document_no,
            'documentStatus' => $document->agt_document_status,
            'documentDate' => $document->document_date->toDateString(),
            'documentType' => $document->document_type->value,
            'eacCode' => $document->legalEntity->main_cae_code,
            'systemEntryDate' => $document->system_entry_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'customerTaxID' => $document->customer_tax_identification_number,
            'customerCountry' => $document->customer_country_code,
            'companyName' => $document->customer_name,
            'lines' => $document->document_type->requiresLines()
                ? $document->lines
                    ->map(fn (FiscalDocumentLine $line): array => $this->line($document, $line))
                    ->values()
                    ->all()
                : null,
            'paymentReceipt' => $this->paymentReceipt($document),
            'documentTotals' => $this->documentTotals($document),
            ...$this->withholdings($document),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{sourceDocuments: list<array<string, mixed>>}|null
     */
    private function paymentReceipt(FiscalDocument $document): ?array
    {
        if (! $document->document_type->settlesOtherDocuments()) {
            return null;
        }

        return [
            'sourceDocuments' => array_values($document->settlements
                ->values()
                ->map(function (FiscalDocumentSettlement $settlement, int $index): array {
                    if ($settlement->net_amount_minor === null || $settlement->tax_amount_minor === null) {
                        throw new DomainException('A schema 2.0 receipt requires frozen net and tax allocations.');
                    }

                    $amountField = $settlement->settledDocument->document_type->reducesReceivable()
                        ? 'debitAmount'
                        : 'creditAmount';

                    return [
                        'lineNo' => $index + 1,
                        'sourceDocumentID' => [
                            'OriginatingON' => $settlement->settled_document_no,
                            'documentDate' => $settlement->settledDocument->document_date->toDateString(),
                        ],
                        $amountField => CanonicalNumber::fromMinorUnits($settlement->net_amount_minor),
                    ];
                })
                ->all()),
        ];
    }

    /**
     * @return array{referenceInfo: array{reference: string|null, reason: string|null}}|array{}
     */
    private function adjustmentReference(FiscalDocument $document): array
    {
        if (! $document->document_type->isAdjustment()) {
            return [];
        }

        return [
            'referenceInfo' => [
                'reference' => $document->references_document_no,
                'reason' => $document->adjustment_reason,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function line(FiscalDocument $document, FiscalDocumentLine $line): array
    {
        $amountField = $document->document_type->reducesReceivable()
            ? 'debitAmount'
            : 'creditAmount';

        return [
            'lineNumber' => $line->line_number,
            'operationType' => $line->operation_type->value,
            ...($line->operation_date === null
                ? []
                : ['operationDate' => $line->operation_date->toDateString()]),
            'productCode' => $line->product_code,
            'productDescription' => $line->product_description,
            'quantity' => CanonicalNumber::fromScaledInteger(
                $line->quantity_units,
                $line->quantity_scale,
            ),
            'unitOfMeasure' => $line->unit_of_measure,
            'unitPriceBase' => CanonicalNumber::fromMinorUnits($line->unit_price_base_minor),
            'unitPrice' => CanonicalNumber::fromScaledInteger($line->unit_price_micros, 6),
            ...$this->adjustmentReference($document),
            $amountField => CanonicalNumber::fromMinorUnits($line->net_amount_minor),
            'taxes' => $line->taxes
                ->map(fn (FiscalDocumentLineTax $tax): array => $this->tax($tax))
                ->values()
                ->all(),
            'settlementAmount' => CanonicalNumber::fromMinorUnits($line->settlement_amount_minor),
        ];
    }

    /** @return array<string, mixed> */
    private function tax(FiscalDocumentLineTax $tax): array
    {
        return array_filter([
            'taxType' => $tax->tax_type->value,
            'taxCountryRegion' => $tax->tax_country_region,
            'taxCode' => $tax->tax_code,
            'taxPercentage' => CanonicalNumber::fromBasisPoints($tax->tax_rate_basis_points),
            'taxContribution' => CanonicalNumber::fromMinorUnits($tax->tax_contribution_minor),
            'taxExemptionCode' => $tax->tax_exemption_code,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Schema 2.0 keeps lines and totals in the document's own currency.
     *
     * @return array<string, mixed>
     */
    private function documentTotals(FiscalDocument $document): array
    {
        return array_filter([
            'taxPayable' => CanonicalNumber::fromMinorUnits($document->tax_payable_minor),
            'netTotal' => CanonicalNumber::fromMinorUnits($document->net_total_minor),
            'grossTotal' => CanonicalNumber::fromMinorUnits($document->gross_total_minor),
            'currency' => $this->currency($document),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The foreign currency code and its gross total converted to kwanzas.
     *
     * @return array{currencyCode: string, currencyAmount: CanonicalNumber, exchangeRate: CanonicalNumber}|null
     */
    private function currency(FiscalDocument $document): ?array
    {
        if (! $document->isForeignCurrency()) {
            return null;
        }

        return [
            'currencyCode' => $document->currency_code,
            'currencyAmount' => CanonicalNumber::fromMinorUnits(
                $this->calculator->convertedAmount($document->gross_total_minor, $document->exchange_rate_micro),
            ),
            'exchangeRate' => CanonicalNumber::fromScaledInteger($document->exchange_rate_micro, 6),
        ];
    }

    /**
     * What the buyer keeps back and pays to the AGT themselves.
     *
     * @return array<string, mixed>
     */
    private function withholdings(FiscalDocument $document): array
    {
        if ($document->withholdings->isEmpty()) {
            return [];
        }

        return [
            'withholdingTaxList' => $document->withholdings
                ->map(fn (FiscalDocumentWithholding $withholding): array => [
                    'withholdingTaxType' => $withholding->withholding_type->agtCode(),
                    'withholdingTaxDescription' => $withholding->rate_basis_points === 0
                        ? $withholding->withholding_type->taxLabel()
                        : sprintf(
                            '%s (%s%%)',
                            $withholding->withholding_type->taxLabel(),
                            CanonicalNumber::fromBasisPoints($withholding->rate_basis_points),
                        ),
                    'withholdingTaxAmount' => CanonicalNumber::fromMinorUnits(
                        $withholding->amount_minor,
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    private function ensureReadyForPayload(FiscalDocument $document): void
    {
        UnsupportedAgtSchema::assertSupported($document->payload_schema_version);
        $document->loadMissing(['legalEntity', 'lines']);

        if (! in_array($document->document_type, FiscalDocumentType::issuable(), true)) {
            throw new DomainException('This document type is not supported by the schema 2.0 issuance flow.');
        }

        if ($document->isMutable()) {
            throw new DomainException('A draft cannot be converted into an AGT payload.');
        }

        if (blank($document->document_no) || $document->system_entry_at === null) {
            throw new DomainException('The issued document has no final fiscal identity.');
        }

        if (blank($document->legalEntity->tax_identification_number)) {
            throw new DomainException('The issuing legal entity has no tax registration number.');
        }

        if ($document->document_type->isReceipt()
            && ($document->payment_method === null || $document->payment_date === null)
        ) {
            throw new DomainException('A receipt must state how and when it was paid.');
        }

        if ($document->document_type->settlesOtherDocuments()
            && $document->settlements()->doesntExist()
        ) {
            throw new DomainException('A receipt must settle at least one issued document.');
        }

        if ($document->document_type->isAdjustment()
            && (blank($document->references_document_no) || blank($document->adjustment_reason))
        ) {
            throw new DomainException(
                'An adjustment document must reference the corrected document and state a reason.',
            );
        }

        if ($document->document_type->requiresLineOperationDate()
            && ($document->lines->isEmpty()
                || $document->lines->contains(fn (FiscalDocumentLine $line): bool => $line->operation_date === null))
        ) {
            throw new DomainException('Every generic or global invoice line must state its operation date.');
        }
    }
}
