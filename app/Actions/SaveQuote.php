<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
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
                'Este orçamento já foi decidido e não pode ser alterado.',
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

        $net = 0;
        $tax = 0;
        $gross = 0;
        $number = 0;

        foreach ($lines as $line) {
            $number++;

            $amounts = $this->lineAmounts($line);

            $quote->lines()->create([
                ...$line,
                'operation_type' => $line['operation_type'] ?? 'SG',
                'line_number' => $number,
                'quantity_scale' => 3,
                ...$amounts,
            ]);

            $net += $amounts['net_amount_minor'];
            $tax += $amounts['tax_amount_minor'];
            $gross += $amounts['gross_amount_minor'];
        }

        return [
            'net_total_minor' => $net,
            'tax_total_minor' => $tax,
            'gross_total_minor' => $gross,
        ];
    }

    /**
     * Line arithmetic in integers throughout.
     *
     * Quantity is scaled by 1e3 and the price is in minor units, so the product
     * is scaled by 1e3 and divided back down once, at the end, rather than
     * rounding at each step.
     *
     * @param  array<string, mixed>  $line
     * @return array{net_amount_minor: int, tax_amount_minor: int, gross_amount_minor: int}
     */
    private function lineAmounts(array $line): array
    {
        $quantity = (int) $line['quantity_units'];
        $unitPrice = (int) $line['unit_price_minor'];
        $discount = (int) $line['discount_rate_basis_points'];

        $base = intdiv($quantity * $unitPrice, 1000);
        $net = $base - intdiv($base * $discount, 10_000);

        $percentage = (string) $line['tax_percentage'];
        $taxBasisPoints = (int) round(((float) $percentage) * 100);
        $tax = intdiv($net * $taxBasisPoints, 10_000);

        return [
            'net_amount_minor' => max(0, $net),
            'tax_amount_minor' => max(0, $tax),
            'gross_amount_minor' => max(0, $net + $tax),
        ];
    }
}
