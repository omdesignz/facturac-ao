<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\MobileCreatedRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileCreatedRecord>
 */
class MobileCreatedRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            'workspace_id' => $customer->workspace_id,
            'legal_entity_id' => $customer->legal_entity_id,
            'customer_id' => $customer->id,
            'client_key' => fake()->uuid(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
