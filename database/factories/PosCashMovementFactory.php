<?php

namespace Database\Factories;

use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\PosCashMovementType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCashMovement>
 */
class PosCashMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'workspace_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('workspace_id'),
            'legal_entity_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('legal_entity_id'),
            'type' => PosCashMovementType::In,
            'amount_minor' => 10_000,
            'reason' => 'Troco',
            'created_by_user_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('opened_by_user_id'),
        ];
    }

    public function withdrawal(): static
    {
        return $this->state(fn (): array => [
            'type' => PosCashMovementType::Out,
            'reason' => 'Retirada para o cofre',
        ]);
    }
}
