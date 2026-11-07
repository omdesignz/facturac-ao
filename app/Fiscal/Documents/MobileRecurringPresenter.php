<?php

namespace App\Fiscal\Documents;

use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Models\RecurringInvoice;
use Carbon\CarbonImmutable;

/**
 * Avenças as the phone reads them: the same estimate and dates as the web's
 * Avenças page, which is where they are set up and changed.
 */
class MobileRecurringPresenter
{
    public function __construct(private FiscalCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function row(RecurringInvoice $profile): array
    {
        return [
            'public_id' => $profile->public_id,
            'name' => $profile->name,
            'customer_name' => $profile->customer->name,
            'document_type_label' => $profile->document_type->label(),
            'frequency_label' => $profile->frequency->label(),
            'is_active' => $profile->is_active,
            'next_run_on' => $profile->next_run_on->toDateString(),
            'estimated_total_minor' => $this->estimate($profile),
            'currency_code' => $profile->legalEntity->currency_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(RecurringInvoice $profile, bool $canAct, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::parse(CarbonImmutable::now('Africa/Luanda')->toDateString());
        $next = CarbonImmutable::parse($profile->next_run_on->toDateString());
        $running = $profile->is_active && ($profile->ends_on === null || ! $next->greaterThan($profile->ends_on));

        return [
            ...$this->row($profile),
            'customer_public_id' => $profile->customer->public_id,
            'auto_issue' => $profile->auto_issue,
            'starts_on' => $profile->starts_on->toDateString(),
            'ends_on' => $profile->ends_on?->toDateString(),
            'last_run_at' => $profile->last_run_at?->toIso8601String(),
            'generated_count' => $profile->generated_count,
            'notes' => $profile->notes,
            // What happens after the next run, so "saltar" can say where it lands.
            'following_run_on' => $profile->frequency->next($next)->toDateString(),
            'can_skip' => $canAct && $running,
            // Raised now only on the eve or once due, and never before it starts.
            'can_run' => $canAct && $running && ! $next->greaterThan($today->addDay())
                && ! ($next->greaterThan($today) ? $today : $next)->lessThan($profile->starts_on),
            // An avença set to issue by itself files with the AGT: that needs the
            // password or a passkey from whoever raises it from the phone.
            'run_needs_proof' => $profile->auto_issue,
            'web_url' => route('recurring.index'),
            'lines' => array_map(fn (array $line): array => [
                'description' => (string) ($line['product_description'] ?? ''),
                'quantity' => (string) CanonicalNumber::fromScaledInteger((int) ($line['quantity_units'] ?? 0), (int) ($line['quantity_scale'] ?? 3)),
                'unit_of_measure' => (string) ($line['unit_of_measure'] ?? 'UN'),
                'unit_price_minor' => (int) ($line['unit_price_minor'] ?? 0),
                'tax_percentage' => (string) ($line['tax_percentage'] ?? '14'),
            ], $profile->lines),
        ];
    }

    /** What one run comes to, calculated the way the web's Avenças page does. */
    public function estimate(RecurringInvoice $profile): int
    {
        return $this->calculator->calculate(array_map(
            fn (array $line): array => [
                'operation_type' => (string) ($line['operation_type'] ?? 'SG'),
                'product_code' => (string) ($line['product_code'] ?? ''),
                'product_description' => (string) ($line['product_description'] ?? ''),
                'quantity' => (string) CanonicalNumber::fromScaledInteger((int) ($line['quantity_units'] ?? 0), (int) ($line['quantity_scale'] ?? 3)),
                'unit_of_measure' => (string) ($line['unit_of_measure'] ?? 'UN'),
                'unit_price' => (string) CanonicalNumber::fromMinorUnits((int) ($line['unit_price_minor'] ?? 0)),
                'discount_percentage' => (string) CanonicalNumber::fromBasisPoints((int) ($line['discount_rate_basis_points'] ?? 0)),
                'tax_type' => (string) ($line['tax_type'] ?? 'IVA'),
                'tax_code' => $line['tax_code'] ?? null,
                'tax_percentage' => (string) ($line['tax_percentage'] ?? '14'),
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
            ],
            $profile->lines,
        ))->grossTotalMinor;
    }
}
