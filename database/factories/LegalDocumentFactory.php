<?php

namespace Database\Factories;

use App\LegalDocumentType;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => LegalDocumentType::Terms,
            'version' => 1,
            'title' => 'Termos e Condições',
            'summary' => 'Resumo das condições de utilização.',
            'body' => "## Objecto\n\nTexto de exemplo.",
            'effective_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => null,
            'effective_at' => null,
        ]);
    }

    public function ofType(LegalDocumentType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'title' => $type->label(),
        ]);
    }
}
