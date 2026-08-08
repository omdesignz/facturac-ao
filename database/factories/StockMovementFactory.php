<?php

namespace Database\Factories;

use App\Models\StockMovement;
use App\StockMovementType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => StockMovementType::Purchase,
            'quantity_units' => 10_000,
            'quantity_scale' => 3,
            'balance_after_units' => 10_000,
            'unit_cost_micros' => 1_000_000,
            'moved_at' => now(),
        ];
    }
}
