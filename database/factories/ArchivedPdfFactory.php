<?php

namespace Database\Factories;

use App\Models\ArchivedPdf;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArchivedPdf>
 */
class ArchivedPdfFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bytes = '%PDF-1.4 '.fake()->sha1();

        return [
            'fiscal_document_id' => FiscalDocument::factory()->issued(),
            'workspace_id' => fn (array $attributes): int => FiscalDocument::query()->whereKey($attributes['fiscal_document_id'])->firstOrFail()->workspace_id,
            'legal_entity_id' => fn (array $attributes): int => FiscalDocument::query()->whereKey($attributes['fiscal_document_id'])->firstOrFail()->legal_entity_id,
            'disk' => 'local',
            'path' => 'fiscal-documents/'.fake()->uuid().'.pdf',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'renderer' => 'mPDF',
            'rendered_at' => now(),
        ];
    }
}
