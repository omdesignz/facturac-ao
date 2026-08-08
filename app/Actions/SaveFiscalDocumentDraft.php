<?php

namespace App\Actions;

use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentSettlement;
use App\Models\FiscalDocumentWithholding;
use App\Models\LegalEntity;
use App\Models\User;
use App\WithholdingType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class SaveFiscalDocumentDraft
{
    public function __construct(
        private FiscalCalculator $calculator,
        private CanonicalJson $canonicalJson,
    ) {}

    /**
     * @param  array{
     *     document_type: string,
     *     document_date: string,
     *     due_date: string|null,
     *     currency_code: string,
     *     exchange_rate_micro?: int,
     *     withholdings?: list<array{type: string, rate_basis_points: int}>,
     *     establishment_public_id: string,
     *     customer_public_id: string|null,
     *     customer: array{name: string, tax_identification_number: string, country_code: string, address_line: string|null},
     *     notes: string|null,
     *     references_document_public_id: string|null,
     *     adjustment_reason: string|null,
     *     payment_method?: string|null,
     *     payment_amount_minor?: int|null,
     *     payment_date?: string|null,
     *     settlements?: list<array{document_public_id: string, amount_minor: int}>,
     *     lines: list<array{
     *         operation_type: string,
     *         product_code: string,
     *         product_description: string,
     *         quantity: string,
     *         unit_of_measure: string,
     *         unit_price: string,
     *         discount_percentage: string,
     *         tax_type: string,
     *         tax_code: string|null,
     *         tax_percentage: string,
     *         tax_exemption_code: string|null
     *     }>
     * }  $profile
     */
    public function execute(
        LegalEntity $legalEntity,
        User $user,
        array $profile,
        ?FiscalDocument $draft = null,
    ): FiscalDocument {
        return DB::transaction(function () use ($legalEntity, $user, $profile, $draft): FiscalDocument {
            $document = $this->lockedDraft($legalEntity, $draft);
            $isNew = $document === null;
            $document ??= new FiscalDocument([
                'workspace_id' => $legalEntity->workspace_id,
                'legal_entity_id' => $legalEntity->id,
                'created_by_user_id' => $user->id,
                'status' => FiscalDocumentStatus::Draft,
                'agt_document_status' => 'N',
                'revision' => 0,
                'payload_schema_version' => (string) config('agt.schema_version', '1.2'),
            ]);
            $document->ensureMutable();

            $establishment = Establishment::query()
                ->where('public_id', $profile['establishment_public_id'])
                ->where('workspace_id', $legalEntity->workspace_id)
                ->where('legal_entity_id', $legalEntity->id)
                ->where('is_active', true)
                ->firstOrFail();
            $customer = $this->customerSnapshot($legalEntity, $profile);
            $referenced = $this->referencedDocument($legalEntity, $profile);
            $type = FiscalDocumentType::from($profile['document_type']);
            $settlements = $this->resolveSettlements($legalEntity, $type, $profile);

            // A standalone receipt carries no goods of its own: its totals are
            // whatever it pays off, so the line calculator does not apply.
            $calculation = $type->requiresLines()
                ? $this->calculator->calculate($profile['lines'])
                : null;
            $totals = $calculation !== null
                ? [
                    'settlement_total_minor' => $calculation->settlementTotalMinor,
                    'net_total_minor' => $calculation->netTotalMinor,
                    'tax_payable_minor' => $calculation->taxPayableMinor,
                    'gross_total_minor' => $calculation->grossTotalMinor,
                ]
                : [
                    'settlement_total_minor' => 0,
                    'net_total_minor' => 0,
                    'tax_payable_minor' => 0,
                    'gross_total_minor' => array_sum(array_column($settlements, 'amount_minor')),
                ];
            $calculationFingerprint = hash(
                'sha256',
                $this->canonicalJson->encode(
                    $calculation !== null
                        ? $calculation->fingerprintData()
                        : ['settlements' => $settlements],
                ),
            );

            $document->fill([
                'establishment_id' => $establishment->id,
                'customer_id' => $customer['id'],
                'updated_by_user_id' => $user->id,
                'document_type' => $profile['document_type'],
                'document_date' => $profile['document_date'],
                'due_date' => $profile['due_date'],
                'currency_code' => $profile['currency_code'],
                'exchange_rate_micro' => $profile['exchange_rate_micro'] ?? 1_000_000,
                'customer_name' => $customer['name'],
                'customer_tax_identification_number' => $customer['tax_identification_number'],
                'customer_country_code' => $customer['country_code'],
                'customer_address' => $customer['address_line'],
                'notes' => $profile['notes'],
                'references_document_id' => $referenced?->id,
                // Snapshotted so the note still shows what it corrected even if
                // the original is later archived.
                'references_document_no' => $referenced?->document_no,
                'adjustment_reason' => $profile['adjustment_reason'] ?? null,
                ...$totals,
                'payment_method' => $profile['payment_method'] ?? null,
                'payment_amount_minor' => $profile['payment_amount_minor'] ?? null,
                'payment_date' => $profile['payment_date'] ?? null,
                'revision' => $document->revision + 1,
                'calculation_sha256' => $calculationFingerprint,
            ])->save();

            FiscalDocumentLine::query()
                ->where('workspace_id', $document->workspace_id)
                ->where('legal_entity_id', $document->legal_entity_id)
                ->where('fiscal_document_id', $document->id)
                ->delete();

            if ($calculation !== null) {
                foreach ($calculation->lines as $calculatedLine) {
                    $this->saveLine($document, $calculatedLine);
                }
            }

            $this->syncSettlements($document, $settlements);
            $withholdings = $this->syncWithholdings($document, $profile);

            activity('fiscal-document')
                ->causedBy($user)
                ->performedOn($document)
                ->event($isNew ? 'fiscal-draft-created' : 'fiscal-draft-updated')
                ->withProperties([
                    'workspace_id' => $document->workspace_id,
                    'legal_entity_id' => $document->legal_entity_id,
                    'document_public_id' => $document->public_id,
                    'document_type' => $document->document_type->value,
                    'revision' => $document->revision,
                    'line_count' => $calculation === null ? 0 : count($calculation->lines),
                    'settlement_count' => count($settlements),
                    'withholding_count' => $withholdings,
                    'calculation_sha256' => $document->calculation_sha256,
                ])
                ->log($isNew ? 'Rascunho fiscal criado.' : 'Rascunho fiscal actualizado.');

            return $document->load(['establishment', 'lines.taxes', 'settlements', 'withholdings']);
        }, 3);
    }

    private function lockedDraft(
        LegalEntity $legalEntity,
        ?FiscalDocument $draft,
    ): ?FiscalDocument {
        if ($draft === null) {
            return null;
        }

        $lockedDraft = FiscalDocument::query()
            ->whereKey($draft->id)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedDraft instanceof FiscalDocument) {
            throw (new ModelNotFoundException)->setModel(FiscalDocument::class, [$draft->id]);
        }

        $lockedDraft->ensureMutable();

        return $lockedDraft;
    }

    /**
     * Resolve the invoices a receipt pays off, refusing anything that is not an
     * issued document of this same company.
     *
     * @param  array<string, mixed>  $profile
     * @return list<array{settled_document_id: int, settled_document_no: string, amount_minor: int}>
     */
    private function resolveSettlements(
        LegalEntity $legalEntity,
        FiscalDocumentType $type,
        array $profile,
    ): array {
        if (! $type->settlesOtherDocuments()) {
            return [];
        }

        $resolved = [];

        foreach ($profile['settlements'] ?? [] as $settlement) {
            $invoice = FiscalDocument::query()
                ->where('public_id', $settlement['document_public_id'])
                ->where('workspace_id', $legalEntity->workspace_id)
                ->where('legal_entity_id', $legalEntity->id)
                ->whereNotNull('document_no')
                ->firstOrFail();

            $resolved[] = [
                'settled_document_id' => $invoice->id,
                'settled_document_no' => (string) $invoice->document_no,
                'amount_minor' => (int) $settlement['amount_minor'],
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array{settled_document_id: int, settled_document_no: string, amount_minor: int}>  $settlements
     */
    private function syncSettlements(FiscalDocument $document, array $settlements): void
    {
        FiscalDocumentSettlement::query()
            ->where('fiscal_document_id', $document->id)
            ->delete();

        foreach ($settlements as $settlement) {
            FiscalDocumentSettlement::query()->create([
                ...$settlement,
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'fiscal_document_id' => $document->id,
            ]);
        }
    }

    /**
     * Records what the buyer keeps back, and returns how many entries there are.
     *
     * The rate comes from the form; the base does not. Which figure a rate is
     * charged on is a matter of law rather than of preference — captive VAT on
     * the VAT, retention on the value of the supply — so it is derived from the
     * document rather than accepted from the request.
     *
     * @param  array<string, mixed>  $profile
     */
    private function syncWithholdings(FiscalDocument $document, array $profile): int
    {
        FiscalDocumentWithholding::query()
            ->where('fiscal_document_id', $document->id)
            ->delete();

        $requested = $profile['withholdings'] ?? [];

        foreach ($requested as $withholding) {
            $type = WithholdingType::from($withholding['type']);
            $rateBasisPoints = (int) $withholding['rate_basis_points'];
            $baseMinor = $type->isLeviedOnVat()
                ? $document->tax_payable_minor
                : $document->net_total_minor;

            FiscalDocumentWithholding::query()->create([
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'fiscal_document_id' => $document->id,
                'withholding_type' => $type,
                'base_minor' => $baseMinor,
                'rate_basis_points' => $rateBasisPoints,
                'amount_minor' => $this->calculator->withheldAmount($baseMinor, $rateBasisPoints),
            ]);
        }

        return count($requested);
    }

    /**
     * Resolve the issued document an adjustment corrects, scoped to the same
     * legal entity so a note can never reference another company's invoice.
     *
     * @param  array<string, mixed>  $profile
     */
    private function referencedDocument(LegalEntity $legalEntity, array $profile): ?FiscalDocument
    {
        $publicId = $profile['references_document_public_id'] ?? null;

        if ($publicId === null || $publicId === '') {
            return null;
        }

        return FiscalDocument::query()
            ->where('public_id', $publicId)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNotNull('document_no')
            ->firstOrFail();
    }

    /**
     * @param  array{
     *     customer_public_id: string|null,
     *     customer: array{
     *         name: string,
     *         tax_identification_number: string,
     *         country_code: string,
     *         address_line: string|null
     *     }
     * }  $profile
     * @return array{id: int|null, name: string, tax_identification_number: string, country_code: string, address_line: string|null}
     */
    private function customerSnapshot(LegalEntity $legalEntity, array $profile): array
    {
        if ($profile['customer_public_id'] === null) {
            return [
                'id' => null,
                ...$profile['customer'],
            ];
        }

        $customer = Customer::query()
            ->where('public_id', $profile['customer_public_id'])
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_active', true)
            ->firstOrFail();

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'country_code' => $customer->country_code,
            'address_line' => $customer->address_line,
        ];
    }

    private function saveLine(FiscalDocument $document, CalculatedFiscalLine $calculatedLine): void
    {
        $line = $document->lines()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'line_number' => $calculatedLine->lineNumber,
            'operation_type' => $calculatedLine->operationType,
            'product_code' => $calculatedLine->productCode,
            'product_description' => $calculatedLine->productDescription,
            'quantity_units' => $calculatedLine->quantityUnits,
            'quantity_scale' => $calculatedLine->quantityScale,
            'unit_of_measure' => $calculatedLine->unitOfMeasure,
            'unit_price_base_minor' => $calculatedLine->unitPriceBaseMinor,
            'unit_price_micros' => $calculatedLine->unitPriceMicros,
            'discount_rate_basis_points' => $calculatedLine->discountRateBasisPoints,
            'base_amount_minor' => $calculatedLine->baseAmountMinor,
            'settlement_amount_minor' => $calculatedLine->settlementAmountMinor,
            'net_amount_minor' => $calculatedLine->netAmountMinor,
            'tax_amount_minor' => $calculatedLine->taxAmountMinor,
            'gross_amount_minor' => $calculatedLine->grossAmountMinor,
        ]);

        $line->taxes()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'fiscal_document_id' => $document->id,
            ...$calculatedLine->tax,
        ]);
    }
}
