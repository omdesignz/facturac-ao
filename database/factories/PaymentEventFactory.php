<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'event_type' => 'checkout-created',
            'event_key' => hash('sha256', fake()->uuid()),
            'payload_sha256' => hash('sha256', 'test-payload'),
            'safe_context' => [],
            'occurred_at' => now(),
        ];
    }
}
