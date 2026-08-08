<?php

namespace Database\Factories;

use App\CatalogueItemType;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogueItem>
 */
class CatalogueItemFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('ART-####-??')),
            'type' => fake()->randomElement(CatalogueItemType::cases()),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'unit_of_measure' => fake()->randomElement(['UN', 'H', 'KG']),
            'unit_price_minor' => fake()->numberBetween(1000, 50000000),
            'currency_code' => 'AOA',
            'tax_type' => 'IVA',
            'tax_code' => 'NOR',
            'tax_percentage' => '14.00',
            'tax_exemption_code' => null,
            'is_active' => true,
            'tracks_stock' => false,
            'stock_scale' => 3,
            'reorder_level_units' => null,
        ];
    }

    /** A physical product whose balance is followed. */
    public function tracked(?int $reorderLevelUnits = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CatalogueItemType::Product,
            'tracks_stock' => true,
            'reorder_level_units' => $reorderLevelUnits,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function resolveLegalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A catalogue item requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
