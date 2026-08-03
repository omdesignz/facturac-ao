<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveLegalEntity($attributes)->workspace_id;
            },
            'name' => fake()->company(),
            'tax_identification_number' => fake()->unique()->numerify('5#########'),
            'country_code' => 'AO',
            'address_line' => fake()->streetAddress(),
            'email' => fake()->companyEmail(),
            'phone' => '+244 '.fake()->numerify('9## ### ###'),
            'is_active' => true,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveLegalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A customer requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
