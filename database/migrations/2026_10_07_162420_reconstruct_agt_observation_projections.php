<?php

use App\Fiscal\Documents\AgtReconstruction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('agt_submissions')->exists() && ! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('Pause fiscal writers, workers and schedulers in maintenance before reconstructing AGT evidence.');
        }
        DB::table('agt_submissions')->select('id')->orderBy('id')->chunkById(100, function ($submissions): void {
            foreach ($submissions as $submission) {
                AgtReconstruction::rebuild($submission->id, function_exists('posix_geteuid') ? posix_geteuid() : null, 'database_migration');
            }
        });
    }

    public function down(): void
    {
        if (DB::table('agt_submission_observations')->exists()) {
            throw new RuntimeException('Reconstruction evidence exists; preserve it and use forward repair.');
        }
    }
};
