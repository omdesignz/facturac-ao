<?php

namespace Database\Factories;

use App\Models\CustomerPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPrice>
 */
class CustomerPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_price_minor' => fake()->numberBetween(10_000, 5_000_000),
            'currency_code' => 'AOA',
            'note' => null,
        ];
    }
}
