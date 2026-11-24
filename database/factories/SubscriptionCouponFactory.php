<?php

namespace Database\Factories;

use App\Models\SubscriptionCoupon;
use App\SubscriptionCouponDiscountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionCoupon>
 */
class SubscriptionCouponFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PROMO-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(4),
            'discount_type' => SubscriptionCouponDiscountType::Percentage,
            'percent_off' => 20,
            'amount_off_minor' => null,
            'free_days' => null,
            'currency_code' => 'AOA',
            'max_redemptions' => null,
            'expires_at' => null,
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function percentOff(int $percent): static
    {
        return $this->state(fn (): array => [
            'discount_type' => SubscriptionCouponDiscountType::Percentage,
            'percent_off' => $percent,
            'amount_off_minor' => null,
        ]);
    }

    public function amountOff(int $amountMinor): static
    {
        return $this->state(fn (): array => [
            'discount_type' => SubscriptionCouponDiscountType::Fixed,
            'percent_off' => null,
            'amount_off_minor' => $amountMinor,
        ]);
    }

    public function freeDays(int $days): static
    {
        return $this->state(fn (): array => [
            'discount_type' => SubscriptionCouponDiscountType::FreeDays,
            'percent_off' => null,
            'amount_off_minor' => null,
            'free_days' => $days,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function limitedTo(int $redemptions): static
    {
        return $this->state(fn (): array => ['max_redemptions' => $redemptions]);
    }
}
