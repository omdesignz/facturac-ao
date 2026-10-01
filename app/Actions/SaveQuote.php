<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\User;
use App\QuoteStatus;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a quote and its lines.
 *
 * The totals are computed here rather than trusted from the browser: a quote
 * becomes an invoice by copying, so a number that is wrong at this stage would
 * be wrong on a fiscal document later.
 */
class SaveQuote
{
    public function __construct(private FiscalCalculator $calculator) {}

    /**
     * @param  array{
     *     customer_public_id: string|null,
     *     customer: array{name: string, tax_identification_number: string|null, country_code: string, address_line: string|null},
     *     establishment_public_id: string,
     *     issue_date: string,
     *     valid_until: string,
     *     notes: string|null,
     *     lines: list<array{
     *         product_code: string|null,
     *         product_description: string,
     *         unit_of_measure: string,
     *         quantity_units: int,
     *         quantity_scale: int,
     *         unit_price_minor: int,
     *         discount_rate_basis_points: int,
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
        ?Quote $quote = null,
    ): Quote {
        if ($quote instanceof Quote && ! $quote->status->isEditable()) {
            throw BillingActionRefused::because(
                'Este orçamento já foi enviado e não pode ser alterado.',
            );
        }

        return DB::transaction(function () use ($legalEntity, $user, $profile, $quote): Quote {
            $establishment = Establishment::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->where('public_id', $profile['establishment_public_id'])
                ->firstOrFail();

            $customer = $profile['customer_public_id'] === null
                ? null
                : Customer::query()
                    ->where('legal_entity_id', $legalEntity->id)
                    ->where('public_id', $profile['customer_public_id'])
                    ->first();

            $quote ??= new Quote;
            $isNew = ! $quote->exists;

            $quote->fill([
                'workspace_id' => $legalEntity->workspace_id,
                'legal_entity_id' => $legalEntity->id,
                'establishment_id' => $establishment->id,
                'customer_id' => $customer?->id,
                'created_by_user_id' => $quote->created_by_user_id ?? $user->id,
                'status' => $quote->status ?? QuoteStatus::Draft,
                'customer_name' => $profile['customer']['name'],
                'customer_tax_identification_number' => $profile['customer']['tax_identification_number'],
                'customer_country_code' => $profile['customer']['country_code'],
                'customer_address' => $profile['customer']['address_line'],
                'issue_date' => $profile['issue_date'],
                'valid_until' => $profile['valid_until'],
                'currency_code' => $legalEntity->currency_code,
                'notes' => $profile['notes'],
            ]);

            if ($isNew) {
                $quote->reference = Quote::nextReference(
                    $legalEntity,
                    (int) substr($profile['issue_date'], 0, 4),
                );
            }

            $quote->save();

            $totals = $this->replaceLines($quote, $profile['lines']);

            $quote->forceFill($totals)->save();

            activity('quote')
                ->causedBy($user)
                ->performedOn($quote)
                ->event($isNew ? 'created' : 'updated')
                ->withProperties([
                    'reference' => $quote->reference,
                    'gross_total_minor' => $quote->gross_total_minor,
                ])
                ->log($isNew ? 'quote created' : 'quote updated');

            return $quote->load('lines');
        });
    }

    /**
     * Replaces the lines wholesale and returns the quote's totals.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{net_total_minor: int, tax_total_minor: int, gross_total_minor: int}
     */
    private function replaceLines(Quote $quote, array $lines): array
    {
        $quote->lines()->delete();

        $calculation = $this->calculator->calculate(array_map(
            fn (array $line): array => [
                'operation_type' => (string) ($line['operation_type'] ?? 'SG'),
                'product_code' => (string) ($line['product_code'] ?? ''),
                'product_description' => (string) $line['product_description'],
                'quantity' => (string) CanonicalNumber::fromScaledInteger(
                    (int) $line['quantity_units'],
                    (int) ($line['quantity_scale'] ?? 4),
                ),
                'unit_of_measure' => (string) $line['unit_of_measure'],
                'unit_price' => (string) CanonicalNumber::fromMinorUnits(
                    (int) $line['unit_price_minor'],
                ),
                'discount_percentage' => (string) CanonicalNumber::fromBasisPoints(
                    (int) $line['discount_rate_basis_points'],
                ),
                'tax_type' => (string) $line['tax_type'],
                'tax_code' => $line['tax_code'] ?? null,
                'tax_percentage' => (string) $line['tax_percentage'],
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
            ],
            $lines,
        ));

        foreach ($calculation->lines as $index => $calculatedLine) {
            $line = $lines[$index];

            $quote->lines()->create([
                'product_code' => $line['product_code'] ?? null,
                'operation_type' => $calculatedLine->operationType,
                'product_description' => $calculatedLine->productDescription,
                'unit_of_measure' => $calculatedLine->unitOfMeasure,
                'line_number' => $calculatedLine->lineNumber,
                'quantity_units' => $calculatedLine->quantityUnits,
                'quantity_scale' => $calculatedLine->quantityScale,
                'unit_price_minor' => $calculatedLine->unitPriceBaseMinor,
                'discount_rate_basis_points' => $calculatedLine->discountRateBasisPoints,
                'tax_type' => (string) $calculatedLine->tax['tax_type'],
                'tax_code' => $calculatedLine->tax['tax_code'],
                'tax_percentage' => (string) CanonicalNumber::fromBasisPoints(
                    (int) $calculatedLine->tax['tax_rate_basis_points'],
                ),
                'tax_exemption_code' => $calculatedLine->tax['tax_exemption_code'],
                'net_amount_minor' => $calculatedLine->netAmountMinor,
                'tax_amount_minor' => $calculatedLine->taxAmountMinor,
                'gross_amount_minor' => $calculatedLine->grossAmountMinor,
            ]);
        }

        return [
            'net_total_minor' => $calculation->netTotalMinor,
            'tax_total_minor' => $calculation->taxPayableMinor,
            'gross_total_minor' => $calculation->grossTotalMinor,
        ];
    }
}
