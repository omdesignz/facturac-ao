<?php

namespace App\Console\Commands;

use App\AgtSubmissionStatus;
use App\Jobs\PollAgtSubmissionStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtSubmission;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agt:dispatch-due-submissions {--limit=500 : Maximum number of records to dispatch}')]
#[Description('Dispatch due AGT registration and status-query outbox jobs')]
class DispatchDueAgtSubmissionPolls extends Command
{
    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $remaining = $limit;
        $registrationCount = 0;
        $pollCount = 0;

        AgtSubmission::query()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    AgtSubmissionStatus::Pending,
                    AgtSubmissionStatus::Retrying,
                ])->where(function ($due): void {
                    $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                });
            })
            ->orWhere(function ($query): void {
                $query->where('status', AgtSubmissionStatus::Sending)
                    ->where('updated_at', '<=', now()->subMinutes(2));
            })
            ->oldest('id')
            ->limit($remaining)
            ->pluck('id')
            ->each(function (int $submissionId) use (&$registrationCount): void {
                SubmitAgtDocument::dispatch($submissionId);
                $registrationCount++;
            });
        $remaining -= $registrationCount;

        if ($remaining > 0) {
            AgtSubmission::query()
                ->whereIn('status', [
                    AgtSubmissionStatus::Received,
                    AgtSubmissionStatus::Processing,
                ])
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->oldest('id')
                ->limit($remaining)
                ->pluck('id')
                ->each(function (int $submissionId) use (&$pollCount): void {
                    PollAgtSubmissionStatus::dispatch($submissionId);
                    $pollCount++;
                });
        }

        $this->components->info(
            "AGT: {$registrationCount} entrega(s) e {$pollCount} consulta(s) colocadas na fila.",
        );

        return self::SUCCESS;
    }
}
