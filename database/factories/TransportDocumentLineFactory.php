<?php

namespace Database\Factories;

use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransportDocumentLine> */
class TransportDocumentLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transport_document_id' => TransportDocument::factory(),
            'workspace_id' => fn (array $attributes): int => $this->document($attributes)->workspace_id,
            'legal_entity_id' => fn (array $attributes): int => $this->document($attributes)->legal_entity_id,
            'catalogue_item_id' => null,
            'line_number' => 1,
            'product_code' => strtoupper(fake()->bothify('ART-####')),
            'product_description' => fake()->words(3, true),
            'quantity_units' => 1_000,
            'quantity_scale' => 3,
            'unit_of_measure' => 'UN',
            'unit_price_minor' => 0,
            'net_amount_minor' => 0,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function document(array $attributes): TransportDocument
    {
        $document = TransportDocument::query()->find($attributes['transport_document_id']);

        if (! $document instanceof TransportDocument) {
            throw new \LogicException('A transport line requires an existing document.');
        }

        return $document;
    }
}
