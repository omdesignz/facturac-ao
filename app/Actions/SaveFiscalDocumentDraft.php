<?php

namespace App\Actions;

use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalDocumentStatus;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\User;
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
     *     establishment_public_id: string,
     *     customer_public_id: string|null,
     *     customer: array{name: string, tax_identification_number: string, country_code: string, address_line: string|null},
     *     notes: string|null,
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
            $calculation = $this->calculator->calculate($profile['lines']);
            $calculationFingerprint = hash(
                'sha256',
                $this->canonicalJson->encode($calculation->fingerprintData()),
            );

            $document->fill([
                'establishment_id' => $establishment->id,
                'customer_id' => $customer['id'],
                'updated_by_user_id' => $user->id,
                'document_type' => $profile['document_type'],
                'document_date' => $profile['document_date'],
                'due_date' => $profile['due_date'],
                'currency_code' => $profile['currency_code'],
                'customer_name' => $customer['name'],
                'customer_tax_identification_number' => $customer['tax_identification_number'],
                'customer_country_code' => $customer['country_code'],
                'customer_address' => $customer['address_line'],
                'notes' => $profile['notes'],
                'settlement_total_minor' => $calculation->settlementTotalMinor,
                'net_total_minor' => $calculation->netTotalMinor,
                'tax_payable_minor' => $calculation->taxPayableMinor,
                'gross_total_minor' => $calculation->grossTotalMinor,
                'revision' => $document->revision + 1,
                'calculation_sha256' => $calculationFingerprint,
            ])->save();

            FiscalDocumentLine::query()
                ->where('workspace_id', $document->workspace_id)
                ->where('legal_entity_id', $document->legal_entity_id)
                ->where('fiscal_document_id', $document->id)
                ->delete();

            foreach ($calculation->lines as $calculatedLine) {
                $this->saveLine($document, $calculatedLine);
            }

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
                    'line_count' => count($calculation->lines),
                    'calculation_sha256' => $document->calculation_sha256,
                ])
                ->log($isNew ? 'Rascunho fiscal criado.' : 'Rascunho fiscal actualizado.');

            return $document->load(['establishment', 'lines.taxes']);
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
