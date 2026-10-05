<?php

namespace Database\Factories;

use App\Models\WiPayToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WiPayToken>
 */
class WiPayTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_fingerprint' => hash('sha256', 'test-merchant'),
            'token_fingerprint' => hash('sha256', fake()->uuid()),
            'scope' => 'payment',
            'access_token' => 'test-token-'.fake()->uuid(),
            'expires_at' => now()->addHour(),
        ];
    }
}
