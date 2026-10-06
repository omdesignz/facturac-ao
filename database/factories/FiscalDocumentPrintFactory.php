<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPrint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocumentPrint>
 */
class FiscalDocumentPrintFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_document_id' => FiscalDocument::factory()->issued(),
            'workspace_id' => fn (array $attributes): int => FiscalDocument::query()->whereKey($attributes['fiscal_document_id'])->firstOrFail()->workspace_id,
            'legal_entity_id' => fn (array $attributes): int => FiscalDocument::query()->whereKey($attributes['fiscal_document_id'])->firstOrFail()->legal_entity_id,
            'layout_version' => 'v1',
            'issuer' => [
                'legal_name' => fake()->company(),
                'trade_name' => null,
                'tax_identification_number' => (string) fake()->numberBetween(5_000_000_000, 5_999_999_999),
                'establishment' => 'Sede',
                'address_line' => fake()->streetAddress(),
                'municipality' => 'Luanda',
                'province_code' => 'LUA',
                'support_email' => null,
            ],
            'logo_path' => null,
            'logo_sha256' => null,
            'source_sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
