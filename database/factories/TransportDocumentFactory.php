<?php

namespace Database\Factories;

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\User;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransportDocument> */
class TransportDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => fn (array $attributes): int => $this->legalEntity($attributes)->workspace_id,
            'establishment_id' => function (array $attributes): int {
                $legalEntity = $this->legalEntity($attributes);

                return Establishment::factory()->headOffice()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'customer_id' => null,
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => User::factory(),
            'document_type' => TransportDocumentType::TransportGuide,
            'status' => TransportDocumentStatus::Draft,
            'movement_date' => now()->toDateString(),
            'movement_start_at' => now()->addHour(),
            'recipient_name' => fake()->company(),
            'recipient_tax_identification_number' => fake()->numerify('5#########'),
            'recipient_country_code' => 'AO',
            'recipient_address' => fake()->streetAddress(),
            'recipient_city' => 'Luanda',
            'recipient_province' => 'Luanda',
            'origin_address' => fake()->streetAddress(),
            'origin_city' => 'Luanda',
            'origin_province' => 'Luanda',
            'origin_country_code' => 'AO',
            'destination_address' => fake()->streetAddress(),
            'destination_city' => 'Luanda',
            'destination_province' => 'Luanda',
            'destination_country_code' => 'AO',
            'currency_code' => 'AOA',
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => TransportDocumentStatus::Issued,
            'document_no' => 'GT TESTE2026/1',
            'issue_sequence' => 1,
            'document_hash' => hash('sha256', 'transport-test'),
            'hash_control' => '0',
            'system_entry_at' => now(),
            'frozen_at' => now(),
            'issued_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function legalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A transport document requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
