<?php

namespace Database\Factories;

use App\Fiscal\Documents\FiscalDocumentNumber;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocument>
 */
class FiscalDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => 'homologation',
            'legal_entity_id' => LegalEntity::factory()->configured(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveLegalEntity($attributes)->workspace_id;
            },
            'establishment_id' => function (array $attributes): int {
                $legalEntity = $this->resolveLegalEntity($attributes);

                return Establishment::factory()->headOffice()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                ])->id;
            },
            'customer_id' => null,
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => User::factory(),
            'document_type' => FiscalDocumentType::Invoice,
            'status' => FiscalDocumentStatus::Draft,
            'agt_document_status' => 'N',
            'document_no' => null,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'AOA',
            'customer_name' => fake()->company(),
            'customer_tax_identification_number' => fake()->numerify('5#########'),
            'customer_country_code' => 'AO',
            'customer_address' => fake()->streetAddress(),
            'notes' => null,
            'settlement_total_minor' => 0,
            'net_total_minor' => 0,
            'tax_payable_minor' => 0,
            'gross_total_minor' => 0,
            'revision' => 1,
            'payload_schema_version' => '2.0',
            'calculation_sha256' => hash('sha256', 'empty-fiscal-draft'),
            'system_entry_at' => null,
            'frozen_at' => null,
            'issued_at' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FiscalDocumentStatus::Issued,
            // Prefixed with the document's own type code, as a real number
            // is: a credit note is "NC TESTE/1", never "FT".
            'document_no' => app(FiscalDocumentNumber::class)->compose(
                FiscalDocumentType::from(
                    $attributes['document_type'] instanceof FiscalDocumentType
                        ? $attributes['document_type']->value
                        : (string) $attributes['document_type'],
                ),
                'TESTE',
                1,
            ),
            'system_entry_at' => now(),
            'frozen_at' => now(),
            'issued_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function resolveLegalEntity(array $attributes): LegalEntity
    {
        $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

        if (! $legalEntity instanceof LegalEntity) {
            throw new \LogicException('A fiscal document requires an existing legal entity.');
        }

        return $legalEntity;
    }
}
