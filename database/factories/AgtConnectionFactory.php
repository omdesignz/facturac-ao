<?php

namespace Database\Factories;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgtConnection>
 */
class AgtConnectionFactory extends Factory
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
                $legalEntity = LegalEntity::query()->find($attributes['legal_entity_id']);

                if (! $legalEntity instanceof LegalEntity) {
                    throw new \LogicException('An AGT connection requires an existing legal entity.');
                }

                return $legalEntity->workspace_id;
            },
            'environment' => AgtEnvironment::Homologation,
            'schema_version' => '1.2',
            'basic_auth_username' => fake()->userName(),
            'basic_auth_password' => fake()->password(16, 32),
            'product_id' => 'VAP Fatura',
            'product_version' => '0.2.0',
            'software_validation_number' => fake()->bothify('AGT-####-????'),
            'establishment_number' => fake()->numerify('########'),
            'software_key_reference' => 'software/test-key',
            'software_key_fingerprint' => hash('sha256', 'software-test-key'),
            'taxpayer_key_reference' => 'taxpayer/test-key',
            'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer-test-key'),
            'status' => AgtConnectionStatus::Ready,
            'configured_at' => now(),
        ];
    }
}
