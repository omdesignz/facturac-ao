<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\PosSale;
use App\Models\PosSession;
use App\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare link row. It points at a factory-made document that was never issued
 * through the pipeline; use the CompletePosSale action for a real sale.
 *
 * @extends Factory<PosSale>
 */
class PosSaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'workspace_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('workspace_id'),
            'legal_entity_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('legal_entity_id'),
            'fiscal_document_id' => function (array $attributes): int {
                $session = PosSession::query()->whereKey($attributes['pos_session_id'])->firstOrFail();

                return FiscalDocument::factory()->issued()->create([
                    'workspace_id' => $session->workspace_id,
                    'legal_entity_id' => $session->legal_entity_id,
                    'establishment_id' => $session->establishment_id,
                ])->id;
            },
            'client_key' => (string) Str::ulid(),
            'payment_method' => PaymentMethod::Cash,
            'total_minor' => 11_400,
            'tendered_minor' => 11_400,
            'change_minor' => 0,
            'created_by_user_id' => fn (array $attributes): int => (int) PosSession::query()->whereKey($attributes['pos_session_id'])->value('opened_by_user_id'),
        ];
    }
}
