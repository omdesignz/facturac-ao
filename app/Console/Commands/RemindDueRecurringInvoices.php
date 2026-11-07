<?php

namespace App\Console\Commands;

use App\Billing\SubscriptionAccess;
use App\Fiscal\Documents\MobileRecurringPresenter;
use App\Models\NotificationDigest;
use App\Models\RecurringInvoice;
use App\Models\Workspace;
use App\Notifications\RecurringInvoiceDue;
use App\SubscriptionFeature;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The evening before an avença runs, tells those who may act on it what it
 * will raise, so they can skip it this once or raise it now from the phone.
 * Says nothing that changes the run: ignored, the morning job runs as always.
 */
#[Signature('recurring:remind-due {--on= : Treat this date as today}')]
#[Description('Tells people the evening before which avenças run tomorrow.')]
class RemindDueRecurringInvoices extends Command
{
    public function __construct(private MobileRecurringPresenter $presenter)
    {
        parent::__construct();
    }

    public function handle(SubscriptionAccess $subscriptions): int
    {
        $today = CarbonImmutable::parse($this->option('on') ?? CarbonImmutable::now('Africa/Luanda')->toDateString());
        $tomorrow = $today->addDay();
        $sent = 0;

        RecurringInvoice::query()
            ->where('is_active', true)
            ->whereDate('next_run_on', $tomorrow->toDateString())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $tomorrow->toDateString()))
            ->with(['customer', 'legalEntity', 'workspace'])
            ->chunkById(100, function ($profiles) use ($subscriptions, &$sent): void {
                foreach ($profiles as $profile) {
                    $workspace = $profile->workspace;

                    if (! $workspace instanceof Workspace || ! $subscriptions->allows($profile->workspace_id, SubscriptionFeature::Recurring)) {
                        continue;
                    }

                    $notification = RecurringInvoiceDue::fromProfile($profile, $this->presenter->estimate($profile));
                    $digest = NotificationDigest::query()->firstOrCreate(
                        ['workspace_id' => $workspace->id, 'dedupe_key' => (string) $notification->dedupeKey()],
                        ['last_sent_at' => now(), 'times_sent' => 1],
                    );

                    if ($digest->wasRecentlyCreated) {
                        $notification->sendToWorkspace($workspace);
                        $sent++;
                    }
                }
            });

        $this->info("{$sent} aviso(s) de avença enviado(s).");

        return self::SUCCESS;
    }
}
