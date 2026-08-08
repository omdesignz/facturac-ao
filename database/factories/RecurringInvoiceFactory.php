<?php

namespace Database\Factories;

use App\FiscalDocumentType;
use App\Models\RecurringInvoice;
use App\RecurrenceFrequency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Avença mensal de manutenção',
            'document_type' => FiscalDocumentType::Invoice,
            'frequency' => RecurrenceFrequency::Monthly,
            'is_active' => true,
            'auto_issue' => false,
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'next_run_on' => now()->toDateString(),
            'lines' => [[
                'product_code' => 'AVE-01',
                'operation_type' => 'SG',
                'product_description' => 'Avença mensal de manutenção',
                'unit_of_measure' => 'UN',
                'quantity_units' => 1_000,
                'unit_price_minor' => 5_000_000,
                'discount_rate_basis_points' => 0,
                'tax_type' => 'IVA',
                'tax_code' => 'NOR',
                'tax_percentage' => '14.00',
                'tax_exemption_code' => null,
            ]],
            'notes' => null,
        ];
    }

    public function autoIssuing(): static
    {
        return $this->state(fn (array $attributes) => ['auto_issue' => true]);
    }
}
