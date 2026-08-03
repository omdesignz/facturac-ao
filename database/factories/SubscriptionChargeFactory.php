<?php

namespace Database\Factories;

use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\SubscriptionChargeStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionCharge>
 */
class SubscriptionChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amountMinor = fake()->numberBetween(5_000, 100_000) * 100;
        $startsAt = now()->startOfDay();

        return [
            'workspace_id' => Workspace::factory(),
            'workspace_subscription_id' => null,
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'created_by_user_id' => null,
            'checkout_token' => (string) Str::ulid(),
            'active_checkout_key' => null,
            'amount_minor' => $amountMinor,
            'currency_code' => 'AOA',
            'provider_fee_basis_points' => 100,
            'estimated_provider_fee_minor' => intdiv($amountMinor * 100, 10_000),
            'status' => SubscriptionChargeStatus::Pending,
            'period_starts_at' => $startsAt,
            'period_ends_at' => $startsAt->addMonthNoOverflow(),
            'due_at' => now()->addDay(),
            'paid_at' => null,
            'failed_at' => null,
            'failure_code' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionChargeStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
