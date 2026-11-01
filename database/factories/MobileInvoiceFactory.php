<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\MobileInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileInvoice>
 */
class MobileInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = FiscalDocument::factory()->create();

        return [
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'fiscal_document_id' => $document->id,
            'client_key' => fake()->uuid(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
