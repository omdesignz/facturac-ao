<?php

namespace Database\Factories;

use App\DataImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataImportRow>
 */
class DataImportRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_import_id' => DataImport::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveDataImport($attributes)->workspace_id;
            },
            'legal_entity_id' => function (array $attributes): int {
                return $this->resolveDataImport($attributes)->legal_entity_id;
            },
            'row_number' => fake()->unique()->numberBetween(2, 100000),
            'status' => DataImportRowStatus::Pending,
            'source_payload' => [
                'nome' => fake()->company(),
                'nif' => fake()->numerify('5#########'),
            ],
            'normalized_payload' => null,
            'validation_errors' => null,
            'target_type' => null,
            'target_id' => null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveDataImport(array $attributes): DataImport
    {
        $dataImport = DataImport::query()->find($attributes['data_import_id']);

        if (! $dataImport instanceof DataImport) {
            throw new \LogicException('A staged row requires an existing data import.');
        }

        return $dataImport;
    }
}
