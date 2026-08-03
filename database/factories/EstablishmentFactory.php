<?php

namespace Database\Factories;

use App\Models\Establishment;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Establishment>
 */
class EstablishmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'workspace_id' => function (array $attributes): int {
                $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

                if (! $legalEntity instanceof LegalEntity) {
                    throw new \LogicException('An establishment requires an existing legal entity.');
                }

                return $legalEntity->workspace_id;
            },
            'code' => fake()->unique()->bothify('EST-###'),
            'name' => fake()->randomElement(['Sede', 'Loja central', 'Armazém']),
            'address_line' => fake()->streetAddress(),
            'municipality' => fake()->city(),
            'province_code' => 'LU',
            'timezone' => 'Africa/Luanda',
            'is_head_office' => false,
            'is_active' => true,
        ];
    }

    public function headOffice(): static
    {
        return $this->state(fn (): array => [
            'code' => 'SEDE',
            'name' => 'Sede',
            'is_head_office' => true,
        ]);
    }
}
