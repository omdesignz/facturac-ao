<?php

namespace Database\Factories;

use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\SubscriptionInterval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPackage>
 */
class SubscriptionPackageFactory extends Factory
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
            'features' => ['Facturação electrónica', 'Suporte em português'],
            'limits' => ['users' => 5],
            'trial_days' => 0,
            'currency_code' => 'AOA',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 10,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    /**
     * Create the package together with one plan row per period, the way the
     * save action leaves them.
     *
     * @param  array<string, int>  $amountsByInterval  interval value => amount in minor units
     */
    public function withPlans(array $amountsByInterval = ['monthly' => 25_000_00, 'annual' => 250_000_00]): static
    {
        return $this->afterCreating(function (SubscriptionPackage $package) use ($amountsByInterval): void {
            foreach ($amountsByInterval as $value => $amountMinor) {
                $interval = SubscriptionInterval::from((string) $value);

                SubscriptionPlan::factory()->create([
                    'subscription_package_id' => $package->id,
                    'code' => $package->code.'-'.$interval->value,
                    'name' => $package->name,
                    'summary' => $package->summary,
                    'features' => $package->features,
                    'limits' => $package->limits,
                    'trial_days' => $package->trial_days,
                    'currency_code' => $package->currency_code,
                    'amount_minor' => $amountMinor,
                    'interval' => $interval,
                    'is_active' => $package->is_active,
                    'sort_order' => $package->sort_order * 10 + $interval->position(),
                ]);
            }
        });
    }
}
