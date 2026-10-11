<?php

namespace Database\Factories;

use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\PosSessionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosSession>
 */
class PosSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => fn (array $attributes): int => $this->legalEntity($attributes)->workspace_id,
            'pos_register_id' => function (array $attributes): int {
                $legalEntity = $this->legalEntity($attributes);

                return PosRegister::factory()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'establishment_id' => fn (array $attributes): int => (int) PosRegister::query()
                ->whereKey($attributes['pos_register_id'])
                ->value('establishment_id'),
            'opened_by_user_id' => User::factory(),
            'closed_by_user_id' => null,
            'status' => PosSessionStatus::Open,
            'currency_code' => 'AOA',
            'opening_float_minor' => 0,
            'opened_at' => now(),
        ];
    }

    public function withFloat(int $minor): static
    {
        return $this->state(fn (): array => ['opening_float_minor' => $minor]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PosSessionStatus::Closed,
            // Resolved after the other attributes, so a factory-made opener is a real id by then.
            'closed_by_user_id' => fn (array $resolved): int => $resolved['opened_by_user_id'],
            'closed_at' => now(),
            'counted_cash_minor' => $attributes['opening_float_minor'] ?? 0,
            'expected_cash_minor' => $attributes['opening_float_minor'] ?? 0,
            'cash_difference_minor' => 0,
            'closing_summary' => null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function legalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A POS session requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
