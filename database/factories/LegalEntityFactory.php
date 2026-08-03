<?php

namespace Database\Factories;

use App\LegalEntityStatus;
use App\Models\LegalEntity;
use App\Models\Workspace;
use App\TaxRegime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
class LegalEntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $legalName = fake()->company();

        return [
            'workspace_id' => Workspace::factory(),
            'legal_name' => $legalName,
            'trade_name' => fake()->boolean(70) ? $legalName : null,
            'tax_identification_number' => fake()->unique()->numerify('5#########'),
            'tax_regime' => fake()->randomElement(TaxRegime::cases()),
            'main_cae_code' => fake()->numerify('#####'),
            'status' => LegalEntityStatus::Draft,
            'country_code' => 'AO',
            'currency_code' => 'AOA',
            'timezone' => 'Africa/Luanda',
            'onboarding_completed_at' => null,
        ];
    }

    public function configured(): static
    {
        return $this->state(fn (): array => [
            'status' => LegalEntityStatus::Configured,
            'onboarding_completed_at' => now(),
        ]);
    }
}
