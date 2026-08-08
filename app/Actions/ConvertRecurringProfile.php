<?php

namespace App\Actions;

use App\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\RecurringInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one due occurrence of a profile into a document.
 *
 * Leaves a draft unless the profile was explicitly set to issue on its own,
 * because issuing files the document with the AGT and cannot be undone. A
 * profile that issues automatically and hits a problem falls back to leaving
 * the draft rather than losing the month's billing entirely.
 */
class ConvertRecurringProfile
{
    public function __construct(private IssueFiscalDocument $issue) {}

    public function execute(RecurringInvoice $profile, CarbonImmutable $runOn): FiscalDocument
    {
        $customer = $profile->customer;

        $document = FiscalDocument::query()->create([
            'workspace_id' => $profile->workspace_id,
            'legal_entity_id' => $profile->legal_entity_id,
            'establishment_id' => $profile->establishment_id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $profile->created_by_user_id,
            'updated_by_user_id' => $profile->created_by_user_id,
            'document_type' => $profile->document_type,
            'status' => FiscalDocumentStatus::Draft,
            'agt_document_status' => 'N',
            'document_date' => $runOn->toDateString(),
            'due_date' => $customer->dueDateFor($runOn)->toDateString(),
            'currency_code' => $profile->legalEntity->currency_code,
            'customer_name' => $customer->name,
            'customer_tax_identification_number' => $customer->tax_identification_number,
            'customer_country_code' => $customer->country_code,
            'customer_address' => $customer->address_line,
            'notes' => $profile->notes,
            'settlement_total_minor' => 0,
            'revision' => 1,
            'payload_schema_version' => (string) config('agt.schema_version', '1.2'),
            'calculation_sha256' => hash('sha256', "recurring:{$profile->public_id}:{$runOn->toDateString()}"),
        ]);

        $totals = $this->buildLines($document, $profile);

        $document->forceFill($totals)->save();

        if ($profile->auto_issue) {
            $this->issueOrLeaveDraft($document, $profile);
        }

        return $document->refresh();
    }

    /**
     * @return array{net_total_minor: int, tax_payable_minor: int, gross_total_minor: int}
     */
    private function buildLines(FiscalDocument $document, RecurringInvoice $profile): array
    {
        $net = 0;
        $tax = 0;
        $gross = 0;
        $number = 0;

        foreach ($profile->lines as $line) {
            $number++;

            $quantity = (int) ($line['quantity_units'] ?? 0);
            $unitPrice = (int) ($line['unit_price_minor'] ?? 0);
            $discount = (int) ($line['discount_rate_basis_points'] ?? 0);

            $base = intdiv($quantity * $unitPrice, 1000);
            $lineNet = $base - intdiv($base * $discount, 10_000);
            $rateBasisPoints = (int) round(((float) ($line['tax_percentage'] ?? '0')) * 100);
            $lineTax = intdiv($lineNet * $rateBasisPoints, 10_000);

            $created = $document->lines()->create([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'line_number' => $number,
                'operation_type' => (string) ($line['operation_type'] ?? 'SG'),
                'product_code' => $line['product_code'] ?? null,
                'product_description' => (string) ($line['product_description'] ?? ''),
                'quantity_units' => $quantity,
                'quantity_scale' => 3,
                'unit_of_measure' => (string) ($line['unit_of_measure'] ?? 'UN'),
                'unit_price_base_minor' => $unitPrice,
                'unit_price_micros' => $unitPrice * 1_000_000,
                'discount_rate_basis_points' => $discount,
                'base_amount_minor' => $lineNet,
                'settlement_amount_minor' => 0,
                'net_amount_minor' => $lineNet,
                'tax_amount_minor' => $lineTax,
                'gross_amount_minor' => $lineNet + $lineTax,
            ]);

            $created->taxes()->create([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'fiscal_document_id' => $document->id,
                'tax_type' => (string) ($line['tax_type'] ?? 'IVA'),
                'tax_country_region' => 'AO',
                'tax_code' => $line['tax_code'] ?? null,
                'tax_rate_basis_points' => $rateBasisPoints,
                'tax_contribution_minor' => $lineTax,
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
            ]);

            $net += $lineNet;
            $tax += $lineTax;
            $gross += $lineNet + $lineTax;
        }

        return [
            'net_total_minor' => $net,
            'tax_payable_minor' => $tax,
            'gross_total_minor' => $gross,
        ];
    }

    /**
     * Issues the document, or leaves it as a draft if anything is not ready.
     *
     * The usual reasons — no open series for the year, an unverified AGT
     * connection — are things a person has to fix. Leaving the draft means the
     * billing is still there to issue once they have.
     */
    private function issueOrLeaveDraft(FiscalDocument $document, RecurringInvoice $profile): void
    {
        $issuer = $profile->created_by_user_id === null ? null : $profile->createdBy()->first();

        if ($issuer === null) {
            return;
        }

        $series = FiscalSeries::query()
            ->where('legal_entity_id', $profile->legal_entity_id)
            ->where('establishment_id', $profile->establishment_id)
            ->where('document_type', $profile->document_type)
            ->where('series_year', (int) $document->document_date->format('Y'))
            ->first();

        if (! $series instanceof FiscalSeries) {
            Log::info('Recurring profile left a draft: no series available.', [
                'profile' => $profile->public_id,
                'document' => $document->public_id,
            ]);

            return;
        }

        try {
            $this->issue->execute($document, $issuer, $series->public_id, $document->revision);
        } catch (Throwable $exception) {
            Log::warning('Recurring profile could not issue; draft left for review.', [
                'profile' => $profile->public_id,
                'document' => $document->public_id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
