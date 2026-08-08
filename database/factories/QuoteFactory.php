<?php

namespace Database\Factories;

use App\Models\Quote;
use App\QuoteStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'ORC '.now()->year.'/'.fake()->unique()->numerify('####'),
            'status' => QuoteStatus::Draft,
            'customer_name' => fake()->company(),
            'customer_tax_identification_number' => fake()->numerify('5#########'),
            'customer_country_code' => 'AO',
            'customer_address' => fake()->streetAddress(),
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'currency_code' => 'AOA',
            'notes' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuoteStatus::Accepted,
            'sent_at' => now()->subDay(),
            'decided_at' => now(),
        ]);
    }

    /** Past its validity while still awaiting an answer. */
    public function lapsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now()->subMonths(2),
            'valid_until' => now()->subWeek()->toDateString(),
        ]);
    }
}
