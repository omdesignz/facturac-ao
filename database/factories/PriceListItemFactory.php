<?php

namespace Database\Factories;

use App\Models\PriceListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
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
