<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationCredential> */
class IntegrationCredentialFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['integration_id' => Integration::factory(), 'secret_hash' => hash('sha256', random_bytes(32)), 'hash_version' => 'sha256-v1',
            'created_by_user_id' => fn (array $attributes) => Integration::query()->whereKey($attributes['integration_id'])->firstOrFail()->sponsor_user_id,
            'creator_principal_kind' => 'user', 'creator_attribution_id' => fn (array $attributes) => Integration::query()->whereKey($attributes['integration_id'])->firstOrFail()->creator_attribution_id,
            'expires_at' => now()->addDays(30)];
    }
}
