<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\PaymentEnvironment;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payable_type' => SubscriptionCharge::class,
            'payable_id' => SubscriptionCharge::factory(),
            'workspace_id' => fn (array $attributes): int => $this->resolveCharge($attributes)->workspace_id,
            'provider' => 'wipay',
            'environment' => PaymentEnvironment::Sandbox,
            'client_fingerprint' => hash('sha256', 'test-merchant'),
            'amount_minor' => fn (array $attributes): int => $this->resolveCharge($attributes)->amount_minor,
            'currency_code' => 'AOA',
            'customer_identifier' => '900000000',
            'status' => PaymentStatus::Pending,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveCharge(array $attributes): SubscriptionCharge
    {
        $charge = SubscriptionCharge::query()->find($attributes['payable_id']);

        if (! $charge instanceof SubscriptionCharge) {
            throw new \LogicException('A payment requires an existing subscription charge.');
        }

        return $charge;
    }
}
