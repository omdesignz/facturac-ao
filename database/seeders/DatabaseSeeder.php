<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(LegalDocumentSeeder::class);
        $this->call(PhaseOneDemoSeeder::class);

        if (filled(config('agt.homologation_fixture.tax_identification_number'))
            && filled(config('agt.homologation_fixture.user_password'))) {
            $this->call(AgtHomologationSeeder::class);
        }
    }
}
