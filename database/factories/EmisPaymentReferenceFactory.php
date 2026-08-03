<?php

namespace Database\Factories;

use App\EmisPaymentReferenceStatus;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\Pay4AllEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmisPaymentReference>
 */
class EmisPaymentReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_charge_id' => SubscriptionCharge::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveCharge($attributes)->workspace_id;
            },
            'provider' => 'pay4all',
            'environment' => Pay4AllEnvironment::Simulation,
            'provider_reference_id' => 'sim_'.Str::lower(Str::random(24)),
            'entity' => '00000',
            'reference' => fake()->unique()->numerify('#########'),
            'amount_minor' => function (array $attributes): int {
                return $this->resolveCharge($attributes)->amount_minor;
            },
            'currency_code' => 'AOA',
            'status' => EmisPaymentReferenceStatus::Pending,
            'expires_at' => now()->addDay(),
            'paid_at' => null,
            'last_checked_at' => null,
            'provider_payload_sha256' => hash('sha256', Str::random(40)),
            'provider_metadata' => ['simulation' => true],
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => EmisPaymentReferenceStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function resolveCharge(array $attributes): SubscriptionCharge
    {
        $charge = SubscriptionCharge::query()->find($attributes['subscription_charge_id']);

        if (! $charge instanceof SubscriptionCharge) {
            throw new \LogicException('An EMIS reference requires an existing subscription charge.');
        }

        return $charge;
    }
}
