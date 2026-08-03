<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\WorkspaceSubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceSubscription>
 */
class WorkspaceSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'status' => WorkspaceSubscriptionStatus::Active,
            'current_period_started_at' => now()->startOfDay(),
            'current_period_ends_at' => now()->addMonthNoOverflow()->startOfDay(),
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkspaceSubscriptionStatus::Pending,
            'current_period_started_at' => null,
            'current_period_ends_at' => null,
        ]);
    }
}
