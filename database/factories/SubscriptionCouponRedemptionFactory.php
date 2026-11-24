<?php

namespace Database\Factories;

use App\Models\SubscriptionCharge;
use App\Models\SubscriptionCoupon;
use App\Models\SubscriptionCouponRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionCouponRedemption>
 */
class SubscriptionCouponRedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_coupon_id' => SubscriptionCoupon::factory(),
            'subscription_charge_id' => SubscriptionCharge::factory(),
            'workspace_id' => fn (array $attributes): int => SubscriptionCharge::query()
                ->findOrFail($attributes['subscription_charge_id'])->workspace_id,
            'list_amount_minor' => 25_000_00,
            'discount_minor' => 5_000_00,
        ];
    }
}
