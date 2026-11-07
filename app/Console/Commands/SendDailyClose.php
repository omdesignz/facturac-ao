<?php

namespace App\Console\Commands;

use App\Analytics\DailyCloseSummary;
use App\Models\LegalEntity;
use App\Models\NotificationDigest;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\DailyCloseReady;
use App\NotificationTopic;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * The end-of-day summary for owners and administrators who asked for it.
 *
 * Runs every hour and speaks for each company when its own clock reaches the
 * closing hour, so a company keeps its own evening. Said once per company per
 * day, however often it runs.
 */
#[Signature('notifications:daily-close {--force : Send now, whatever the hour} {--on= : Close this date instead of today}')]
#[Description('Sends owners and administrators the day\'s sales, money received and what is still owed.')]
class SendDailyClose extends Command
{
    public function __construct(private DailyCloseSummary $summary)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = 0;
        $hour = (int) config('notifications.daily_close_hour', 19);

        foreach (Workspace::query()->with('legalEntities')->cursor() as $workspace) {
            $recipients = $this->recipients($workspace);

            if ($recipients === []) {
                continue;
            }

            foreach ($workspace->legalEntities as $legalEntity) {
                $local = CarbonImmutable::now($legalEntity->timezone ?: 'Africa/Luanda');

                if (! $this->option('force') && $local->hour !== $hour) {
                    continue;
                }

                // The company's date, read on the app's clock as Hoje reads it.
                $day = CarbonImmutable::parse($this->option('on') === null ? $local->toDateString() : (string) $this->option('on'));

                $sent += $this->close($workspace, $legalEntity, $day, $recipients);
            }
        }

        $this->info("{$sent} fecho(s) do dia enviado(s).");

        return self::SUCCESS;
    }

    /**
     * Owners and administrators still active in the company, who want the topic
     * on at least one channel. Everyone else is left out before anything is counted.
     *
     * @return list<User>
     */
    private function recipients(Workspace $workspace): array
    {
        return array_values($workspace->users()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Administrator->value])
            ->wherePivot('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => collect(['database', 'mail', 'push'])
                ->contains(fn (string $channel): bool => $user->wantsNotification(NotificationTopic::DailyClose, $channel)))
            ->all());
    }

    /**
     * @param  list<User>  $recipients
     */
    private function close(Workspace $workspace, LegalEntity $legalEntity, CarbonImmutable $day, array $recipients): int
    {
        $summary = $this->summary->for($legalEntity, $day);

        // A company that has never billed anything has no day to close.
        if ($summary['issued_count'] === 0 && $summary['received_minor'] === 0 && $summary['outstanding_minor'] === 0) {
            return 0;
        }

        $notification = new DailyCloseReady($summary, $legalEntity->public_id, $workspace->id);

        // Claimed before sending, as the daily sweep does: delivery is queued,
        // and a second run in the same hour must not say it again.
        $digest = NotificationDigest::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'dedupe_key' => (string) $notification->dedupeKey()],
            ['last_sent_at' => now(), 'times_sent' => 0],
        );

        if (! $digest->wasRecentlyCreated) {
            return 0;
        }

        $digest->forceFill(['times_sent' => 1])->save();
        Notification::send($recipients, $notification);

        return 1;
    }
}
