<?php

namespace Database\Factories;

use App\FiscalOperationType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocumentLine>
 */
class FiscalDocumentLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_document_id' => FiscalDocument::factory(),
            'workspace_id' => function (array $attributes): int {
                return $this->resolveDocument($attributes)->workspace_id;
            },
            'legal_entity_id' => function (array $attributes): int {
                return $this->resolveDocument($attributes)->legal_entity_id;
            },
            'line_number' => 1,
            'operation_type' => FiscalOperationType::GoodsTransfer,
            'product_code' => fake()->bothify('ART-###'),
            'product_description' => fake()->words(3, true),
            'quantity_units' => 10_000,
            'quantity_scale' => 4,
            'unit_of_measure' => 'un',
            'unit_price_base_minor' => 100_00,
            'unit_price_micros' => 100_000_000,
            'discount_rate_basis_points' => 0,
            'base_amount_minor' => 100_00,
            'settlement_amount_minor' => 0,
            'net_amount_minor' => 100_00,
            'tax_amount_minor' => 14_00,
            'gross_amount_minor' => 114_00,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveDocument(array $attributes): FiscalDocument
    {
        $document = FiscalDocument::query()->find($attributes['fiscal_document_id']);

        if (! $document instanceof FiscalDocument) {
            throw new \LogicException('A fiscal line requires an existing document.');
        }

        return $document;
    }
}
