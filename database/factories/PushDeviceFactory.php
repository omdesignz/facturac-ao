<?php

namespace Database\Factories;

use App\Actions\SignInMobileDevice;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'personal_access_token_id' => fn (array $attributes): int => User::query()->whereKey($attributes['user_id'])->firstOrFail()
                ->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY], now()->addDays(SignInMobileDevice::TOKEN_LIFETIME_DAYS))
                ->accessToken
                ->getKey(),
            'token' => bin2hex(random_bytes(32)),
            'environment' => 'production',
        ];
    }
}
