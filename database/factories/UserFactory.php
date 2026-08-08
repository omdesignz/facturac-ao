<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'work_session_minutes' => (int) config('work_session.default_minutes'),
            'is_support_staff' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** One of the platform's own support people, able to impersonate a customer. */
    public function supportStaff(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_support_staff' => true,
        ]);
    }

    public function withWorkspace(?string $workspaceName = null): static
    {
        return $this->afterCreating(function (User $user) use ($workspaceName): void {
            $workspace = Workspace::factory()->create([
                'name' => $workspaceName ?? fake()->company(),
                'created_by_user_id' => $user->id,
            ]);

            WorkspaceMembership::factory()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => WorkspaceRole::Owner,
            ]);

            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        });
    }
}
