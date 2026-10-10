<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Integration> */
class IntegrationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $user = User::factory()->withWorkspace()->create();
        $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
        $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);

        return ['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'homologation', 'name' => 'Read integration',
            'sponsor_user_id' => $user->id, 'sponsor_membership_id' => WorkspaceMembership::where('user_id', $user->id)->firstOrFail()->id,
            'creator_principal_kind' => 'user', 'creator_attribution_id' => $user->attribution_id];
    }
}
