<?php

namespace App\Fiscal\Documents\V1_2;

use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use DomainException;

final class FiscalDocumentPayloadBuilder
{
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
        $document->loadMissing(['legalEntity', 'lines.taxes']);

        return array_filter([
            'documentNo' => $document->document_no,
            'documentStatus' => $document->agt_document_status,
            'documentDate' => $document->document_date->toDateString(),
            'documentType' => $document->document_type->value,
            'eacCode' => $document->legalEntity->main_cae_code,
            'systemEntryDate' => $document->system_entry_at?->toIso8601String(),
            'customerTaxID' => $document->customer_tax_identification_number,
            'customerCountry' => $document->customer_country_code,
            'companyName' => $document->customer_name,
            'lines' => $document->lines
                ->map(fn (FiscalDocumentLine $line): array => $this->line($line))
                ->values()
                ->all(),
            'documentTotals' => $this->documentTotals($document),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function line(FiscalDocumentLine $line): array
    {
        return [
            'lineNumber' => (string) $line->line_number,
            'operationType' => $line->operation_type->value,
            'productCode' => $line->product_code,
            'productDescription' => $line->product_description,
            'quantity' => CanonicalNumber::fromScaledInteger(
                $line->quantity_units,
                $line->quantity_scale,
            ),
            'unitOfMeasure' => $line->unit_of_measure,
            'unitPriceBase' => CanonicalNumber::fromMinorUnits($line->unit_price_base_minor),
            'unitPrice' => CanonicalNumber::fromScaledInteger($line->unit_price_micros, 6),
            'debitAmount' => CanonicalNumber::fromMinorUnits($line->net_amount_minor),
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

    /** @return array<string, CanonicalNumber> */
    private function documentTotals(FiscalDocument $document): array
    {
        return [
            'taxPayable' => CanonicalNumber::fromMinorUnits($document->tax_payable_minor),
            'netTotal' => CanonicalNumber::fromMinorUnits($document->net_total_minor),
            'grossTotal' => CanonicalNumber::fromMinorUnits($document->gross_total_minor),
        ];
    }

    private function ensureReadyForPayload(FiscalDocument $document): void
    {
        $document->loadMissing('legalEntity');

        if ($document->isMutable()) {
            throw new DomainException('A draft cannot be converted into an AGT payload.');
        }

        if (blank($document->document_no) || $document->system_entry_at === null) {
            throw new DomainException('The issued document has no final fiscal identity.');
        }

        if (blank($document->legalEntity->tax_identification_number)) {
            throw new DomainException('The issuing legal entity has no tax registration number.');
        }
    }
}
