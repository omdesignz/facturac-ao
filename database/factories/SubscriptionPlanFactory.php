<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\SubscriptionInterval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'code' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'name' => Str::headline($name),
            'summary' => fake()->sentence(),
            'amount_minor' => fake()->numberBetween(5_000, 100_000) * 100,
            'currency_code' => 'AOA',
            'interval' => SubscriptionInterval::Monthly,
            'trial_days' => 0,
            'features' => ['Facturação electrónica', 'Suporte em português'],
            'limits' => ['users' => 5],
            'is_active' => true,
            'sort_order' => 10,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
