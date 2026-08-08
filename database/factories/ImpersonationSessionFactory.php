<?php

namespace Database\Factories;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImpersonationSession>
 */
class ImpersonationSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'impersonator_id' => User::factory()->supportStaff(),
            'subject_id' => User::factory(),
            'workspace_id' => null,
            'reason' => 'Cliente reportou erro ao emitir a factura FT 2026/14.',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'started_at' => now(),
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now(),
            'ended_by' => 'support',
        ]);
    }
}
