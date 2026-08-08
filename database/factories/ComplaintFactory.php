<?php

namespace Database\Factories;

use App\ComplaintCategory;
use App\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'REC-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'user_id' => User::factory(),
            'category' => ComplaintCategory::Invoicing,
            'status' => ComplaintStatus::Open,
            'subject' => 'A factura não segue para a AGT',
            'body' => 'Emiti a factura FT 2026/14 e passadas duas horas continua por comunicar.',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+244 923 000 111',
            'response_due_at' => now()->addWeekdays(5),
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ComplaintStatus::Resolved,
            'resolution' => 'Corrigido o certificado da série e recomunicado o documento.',
            'resolved_at' => now(),
            'acknowledged_at' => now()->subDay(),
        ]);
    }
}
