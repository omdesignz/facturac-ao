<?php

namespace Database\Factories;

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosRegister>
 */
class PosRegisterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => fn (array $attributes): int => $this->legalEntity($attributes)->workspace_id,
            'establishment_id' => function (array $attributes): int {
                $legalEntity = $this->legalEntity($attributes);

                return Establishment::factory()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'name' => 'Caixa '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** @param array<string, mixed> $attributes */
    private function legalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A POS register requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
