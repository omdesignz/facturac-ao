<?php

namespace Database\Factories;

use App\Analytics\TransactionReport;
use App\Models\LegalEntity;
use App\Models\TransactionExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionExport> */
class TransactionExportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'workspace_id' => fn (array $attributes): int => LegalEntity::query()->whereKey($attributes['legal_entity_id'])->firstOrFail()->workspace_id,
            'user_id' => User::factory(),
            'filters' => ['title' => 'Relatório', 'from' => '2026-10-01', 'to' => '2026-10-31', 'columns' => TransactionReport::DEFAULT_COLUMNS],
            'document_ids' => [],
            'expires_at' => now()->addDay(),
        ];
    }
}
