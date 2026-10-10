<?php

namespace App\Actions;

use App\AgtEnvironment;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\ExecutionContext;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Turns one due occurrence of a profile into a document.
 *
 * Leaves a draft unless the profile was explicitly set to issue on its own,
 * because issuing files the document with the AGT and cannot be undone. A
 * profile that issues automatically and hits a problem falls back to leaving
 * the draft rather than losing the month's billing entirely.
 *
 * @phpstan-import-type FiscalLineProfile from FiscalCalculator
 */
class ConvertRecurringProfile
{
    public function __construct(
        private IssueFiscalDocument $issue,
        private FiscalCalculator $calculator,
        private CanonicalJson $canonicalJson,
    ) {}

    public function execute(RecurringInvoice $profile, CarbonImmutable $runOn): FiscalDocument
    {
        return Context::scope(fn (): FiscalDocument => DB::transaction(function () use ($profile, $runOn): FiscalDocument {
            $profile = RecurringInvoice::query()->lockForUpdate()->findOrFail($profile->id);
            $existing = DB::table('recurring_occurrences')->where('recurring_invoice_id', $profile->id)
                ->whereDate('scheduled_on', $runOn->toDateString())->first();
            if ($existing !== null) {
                return FiscalDocument::query()->where('workspace_id', $profile->workspace_id)
                    ->where('legal_entity_id', $profile->legal_entity_id)->findOrFail((int) $existing->fiscal_document_id);
            }
            abort_unless($profile->is_active && $runOn->toDateString() >= $profile->starts_on->toDateString()
                && ($profile->ends_on === null || $runOn->toDateString() <= $profile->ends_on->toDateString()), 409);
            ApproveRecurringInvoice::fingerprint($profile);
            $document = $this->convert($profile, $runOn);
            DB::table('recurring_occurrences')->insert([
                'recurring_invoice_id' => $profile->id, 'scheduled_on' => $runOn->toDateString(),
                'fiscal_document_id' => $document->id,
                'recurring_approval_id' => $document->status === FiscalDocumentStatus::Draft ? null : DB::table('recurring_approvals')->where('recurring_invoice_id', $profile->id)->whereNull('revoked_at')->latest('id')->value('id'),
                'configuration_revision' => $profile->configuration_revision,
                'completed_at' => now(),
            ]);

            return $document;
        }, 5), [
            'request_id' => Context::get('request_id') ?? (string) Str::uuid(),
            'automation_id' => $profile->public_id,
            'idempotency_reference' => $profile->public_id.':'.$runOn->toDateString(),
        ]);
    }

    private function convert(RecurringInvoice $profile, CarbonImmutable $runOn): FiscalDocument
    {
        $customer = $profile->customer;
        $calculation = $this->calculator->calculate($this->lineProfiles($profile), $profile->document_type);

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
            'settlement_total_minor' => $calculation->settlementTotalMinor,
            'net_total_minor' => $calculation->netTotalMinor,
            'tax_payable_minor' => $calculation->taxPayableMinor,
            'gross_total_minor' => $calculation->grossTotalMinor,
            'revision' => 1,
            'payload_schema_version' => '2.0',
            'calculation_sha256' => hash(
                'sha256',
                $this->canonicalJson->encode($calculation->fingerprintData()),
            ),
        ]);

        foreach ($calculation->lines as $line) {
            $this->saveLine($document, $line);
        }

        if ($profile->auto_issue) {
            $this->issueOrLeaveDraft($document, $profile);
        }

        return $document->refresh();
    }

    /** @return list<FiscalLineProfile> */
    private function lineProfiles(RecurringInvoice $profile): array
    {
        return array_map(function (array $line, int $index): array {
            $treatment = SupportedTaxTreatment::fromLegacyComponents(
                $line['tax_type'] ?? 'IVA',
                $line['tax_code'] ?? null,
                $line['tax_percentage'] ?? '14',
                $line['tax_exemption_code'] ?? null,
            );

            if (! $treatment instanceof SupportedTaxTreatment) {
                throw new DomainException(
                    'A avença contém um tratamento fiscal que não pode ser emitido.',
                );
            }

            $description = (string) ($line['product_description'] ?? '');
            $productCode = (string) ($line['product_code'] ?? '');

            if ($productCode === '') {
                $productCode = Str::upper(Str::slug($description));
                $productCode = $productCode === ''
                    ? 'L'.($index + 1)
                    : Str::limit($productCode, 60, '');
            }

            $tax = $treatment->profile();

            return [
                'operation_type' => (string) ($line['operation_type'] ?? 'SG'),
                'product_code' => $productCode,
                'product_description' => $description,
                'quantity' => (string) CanonicalNumber::fromScaledInteger(
                    (int) ($line['quantity_units'] ?? 0),
                    (int) ($line['quantity_scale'] ?? 3),
                ),
                'unit_of_measure' => (string) ($line['unit_of_measure'] ?? 'UN'),
                'unit_price' => (string) CanonicalNumber::fromMinorUnits(
                    (int) ($line['unit_price_minor'] ?? 0),
                ),
                'discount_percentage' => (string) CanonicalNumber::fromBasisPoints(
                    (int) ($line['discount_rate_basis_points'] ?? 0),
                ),
                'tax_type' => $tax['type'],
                'tax_code' => $tax['code'],
                'tax_percentage' => $tax['percentage'],
                'tax_exemption_code' => $tax['exemption_code'],
            ];
        }, $profile->lines, array_keys($profile->lines));
    }

    private function saveLine(
        FiscalDocument $document,
        CalculatedFiscalLine $line,
    ): void {
        $created = $document->lines()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'line_number' => $line->lineNumber,
            'operation_type' => $line->operationType,
            'product_code' => $line->productCode,
            'product_description' => $line->productDescription,
            'quantity_units' => $line->quantityUnits,
            'quantity_scale' => $line->quantityScale,
            'unit_of_measure' => $line->unitOfMeasure,
            'unit_price_base_minor' => $line->unitPriceBaseMinor,
            'unit_price_micros' => $line->unitPriceMicros,
            'discount_rate_basis_points' => $line->discountRateBasisPoints,
            'base_amount_minor' => $line->baseAmountMinor,
            'settlement_amount_minor' => $line->settlementAmountMinor,
            'net_amount_minor' => $line->netAmountMinor,
            'tax_amount_minor' => $line->taxAmountMinor,
            'gross_amount_minor' => $line->grossAmountMinor,
        ]);

        $created->taxes()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'fiscal_document_id' => $document->id,
            ...$line->tax,
        ]);
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
        $approval = DB::table('recurring_approvals')->where('recurring_invoice_id', $profile->id)
            ->latest('id')->first();
        $issuer = $approval === null ? null : User::query()->find((int) $approval->approved_by_user_id);
        $context = null;
        if ($approval !== null && $issuer !== null && $approval->revoked_at === null
            && CarbonImmutable::parse($approval->expires_at)->isFuture()
            && (int) $approval->configuration_revision === $profile->configuration_revision
            && hash_equals($approval->configuration_sha256, ApproveRecurringInvoice::fingerprint($profile))) {
            try {
                $context = ExecutionContext::resolve($issuer, $profile->legalEntity,
                    AgtEnvironment::from($approval->environment), $approval->id, $profile->public_id);
            } catch (HttpException $exception) {
                $context = null;
            }
        }

        if ($context === null || $issuer === null || Gate::forUser($issuer)->denies('issueAutomatically', $document)) {
            activity('recurring-invoice')
                ->causedBy($issuer)
                ->performedOn($profile)
                ->event('automatic-issuance-refused')
                ->withProperties([
                    'workspace_id' => $profile->workspace_id,
                    'legal_entity_id' => $profile->legal_entity_id,
                    'document_public_id' => $document->public_id,
                    'reason' => 'current_issuance_authority_missing',
                    'actor_kind' => 'scheduled', 'automation_id' => $profile->public_id,
                    'approval_id' => $approval->id ?? null,
                    'authority_user_id' => $approval->approved_by_user_id ?? null,
                ])
                ->log('Avença mantida em rascunho por falta de autorização actual para emitir.');

            return;
        }

        $series = FiscalSeries::query()
            ->where('legal_entity_id', $profile->legal_entity_id)
            ->where('workspace_id', $profile->workspace_id)
            ->whereHas('agtConnection', fn ($query) => $query->where('environment', $context->environment))
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
            activity('recurring-invoice')->performedOn($profile)->causedBy($issuer)->event('approved-occurrence-issued')
                ->withProperties([...$context->audit(), 'document_public_id' => $document->public_id])->log('Approved occurrence issued');
        } catch (Throwable $exception) {
            Log::warning('Recurring profile could not issue; draft left for review.', [
                'profile' => $profile->public_id,
                'document' => $document->public_id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
