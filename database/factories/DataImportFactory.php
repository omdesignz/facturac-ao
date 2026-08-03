<?php

namespace Database\Factories;

use App\DataImportSource;
use App\DataImportStatus;
use App\DataImportType;
use App\Models\DataImport;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataImport>
 */
class DataImportFactory extends Factory
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
            'uploaded_by_user_id' => User::factory(),
            'type' => DataImportType::Customers,
            'source' => DataImportSource::Csv,
            'status' => DataImportStatus::AwaitingMapping,
            'original_name' => 'clientes.csv',
            'storage_disk' => 'local',
            'storage_path' => 'imports/'.fake()->uuid().'.csv',
            'file_extension' => 'csv',
            'mime_type' => 'text/csv',
            'file_size' => fake()->numberBetween(100, 100000),
            'sha256' => hash('sha256', fake()->uuid()),
            'headers' => ['nome', 'nif'],
            'column_mapping' => null,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'imported_rows' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'failure_code' => null,
            'failure_message' => null,
            'mapped_at' => null,
            'validated_at' => null,
            'committed_at' => null,
            'cancelled_at' => null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveLegalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A data import requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
