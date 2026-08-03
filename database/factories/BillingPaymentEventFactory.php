<?php

namespace Database\Factories;

use App\BillingPaymentEventType;
use App\Models\BillingPaymentEvent;
use App\Models\EmisPaymentReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingPaymentEvent>
 */
class BillingPaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emis_payment_reference_id' => EmisPaymentReference::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolvePaymentReference($attributes)->workspace_id;
            },
            'actor_user_id' => null,
            'event_type' => BillingPaymentEventType::ReferenceCreated,
            'provider_event_id' => null,
            'payload_sha256' => hash('sha256', Str::random(40)),
            'safe_context' => ['provider' => 'pay4all'],
            'occurred_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolvePaymentReference(array $attributes): EmisPaymentReference
    {
        $reference = EmisPaymentReference::query()->find($attributes['emis_payment_reference_id']);

        if (! $reference instanceof EmisPaymentReference) {
            throw new \LogicException('A billing event requires an existing EMIS reference.');
        }

        return $reference;
    }
}
