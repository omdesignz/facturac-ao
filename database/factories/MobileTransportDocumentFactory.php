<?php

namespace Database\Factories;

use App\Models\MobileTransportDocument;
use App\Models\TransportDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileTransportDocument>
 */
class MobileTransportDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = TransportDocument::factory()->create();

        return [
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'transport_document_id' => $document->id,
            'client_key' => fake()->uuid(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
