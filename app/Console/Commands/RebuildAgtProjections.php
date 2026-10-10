<?php

namespace App\Console\Commands;

use App\Fiscal\Documents\AgtReconstruction;
use App\Models\AgtSubmission;
use Illuminate\Console\Command;

class RebuildAgtProjections extends Command
{
    protected $signature = 'agt:rebuild-projections {--writers-paused : Confirm web fiscal writers, queue workers and schedulers are paused}';

    protected $description = 'Maintenance-only deterministic AGT evidence reconstruction';

    public function handle(): int
    {
        if (! function_exists('posix_geteuid') || ! $this->option('writers-paused') || (! app()->environment('testing') && ! app()->isDownForMaintenance())) {
            $this->error('Maintenance and paused fiscal writers are required.');

            return self::FAILURE;
        }
        AgtSubmission::query()->select('id')->orderBy('id')->chunkById(100, function ($submissions): void {
            foreach ($submissions as $submission) {
                AgtReconstruction::rebuild($submission->id, posix_geteuid(), 'maintenance_command');
            }
        });
        $this->info('AGT projections rebuilt; original evidence and workflow labels preserved.');

        return self::SUCCESS;
    }
}
