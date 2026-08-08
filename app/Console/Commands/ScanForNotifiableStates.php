<?php

namespace App\Console\Commands;

use App\Analytics\ReceivablesQuery;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\NotificationDigest;
use App\Models\Quote;
use App\Models\Workspace;
use App\Notifications\CustomerOverCreditLimit;
use App\Notifications\InvoiceFellOverdue;
use App\Notifications\QuoteExpiringSoon;
use App\Notifications\WorkspaceNotification;
use App\QuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The things nobody does — they simply become true.
 *
 * An invoice falling due, a quote running out of days and a customer creeping
 * past their limit have no click behind them, so nothing would ever raise them.
 * This sweep does, once a day, and refuses to say the same thing twice.
 */
#[Signature('notifications:scan {--on= : Treat this date as today}')]
#[Description('Raises notifications for overdue invoices, expiring quotes and customers over their credit limit.')]
class ScanForNotifiableStates extends Command
{
    public function __construct(private ReceivablesQuery $receivables)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = $this->option('on') === null
            ? CarbonImmutable::parse(CarbonImmutable::now('Africa/Luanda')->toDateString())
            : CarbonImmutable::parse((string) $this->option('on'));

        $raised = 0;

        foreach (Workspace::query()->with('legalEntities')->cursor() as $workspace) {
            foreach ($workspace->legalEntities as $legalEntity) {
                $raised += $this->overdueInvoices($workspace, $legalEntity, $today);
                $raised += $this->expiringQuotes($workspace, $legalEntity, $today);
                $raised += $this->overLimitCustomers($workspace, $legalEntity);
            }
        }

        $this->info("{$raised} notificação(ões) levantada(s).");

        return self::SUCCESS;
    }

    private function overdueInvoices(
        Workspace $workspace,
        LegalEntity $legalEntity,
        CarbonImmutable $today,
    ): int {
        $documents = $this->receivables
            ->withBalances($this->receivables->forLegalEntity($legalEntity))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        $raised = 0;

        foreach ($documents as $document) {
            $outstanding = $this->receivables->outstandingMinor($document);

            if ($outstanding <= 0) {
                continue;
            }

            $daysPastDue = (int) $today->diffInDays($document->due_date, absolute: true);

            $raised += $this->raise(
                $workspace,
                InvoiceFellOverdue::fromDocument($document, $outstanding, $daysPastDue),
            );
        }

        return $raised;
    }

    private function expiringQuotes(
        Workspace $workspace,
        LegalEntity $legalEntity,
        CarbonImmutable $today,
    ): int {
        $quotes = Quote::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Accepted])
            ->whereDate('valid_until', '>=', $today->toDateString())
            ->whereDate('valid_until', '<=', $today->addDays(3)->toDateString())
            ->get();

        $raised = 0;

        foreach ($quotes as $quote) {
            $daysLeft = (int) $today->diffInDays($quote->valid_until, absolute: true);

            $raised += $this->raise(
                $workspace,
                QuoteExpiringSoon::fromQuote($quote, $daysLeft),
            );
        }

        return $raised;
    }

    private function overLimitCustomers(Workspace $workspace, LegalEntity $legalEntity): int
    {
        $balances = [];

        $documents = $this->receivables
            ->withBalances($this->receivables->forLegalEntity($legalEntity))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->whereNotNull('customer_id')
            ->get();

        foreach ($documents as $document) {
            $balances[(int) $document->customer_id] =
                ($balances[(int) $document->customer_id] ?? 0)
                + $this->receivables->outstandingMinor($document);
        }

        $raised = 0;

        $customers = Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNotNull('credit_limit_minor')
            ->whereIn('id', array_keys($balances))
            ->with('legalEntity:id,currency_code')
            ->get();

        foreach ($customers as $customer) {
            $owed = $balances[$customer->id] ?? 0;

            if ($owed <= (int) $customer->credit_limit_minor) {
                continue;
            }

            $raised += $this->raise(
                $workspace,
                CustomerOverCreditLimit::fromCustomer($customer, $owed),
            );
        }

        return $raised;
    }

    /**
     * Sends unless the same thing has already been said recently.
     *
     * The key is the subject, not the wording: an invoice a week overdue and
     * the same invoice a fortnight overdue are one piece of news that has not
     * changed, and repeating it daily is how a bell stops being read. It does
     * come round again after the cooling-off, because a debt still unpaid a
     * month later has earned a second mention.
     */
    private function raise(Workspace $workspace, WorkspaceNotification $notification): int
    {
        $key = $notification->dedupeKey();

        if ($key === null) {
            $notification->sendToWorkspace($workspace);

            return 1;
        }

        $silentUntil = now()->subDays($this->coolingOffDays());

        // The digest is written before the notification is sent, not after:
        // delivery is queued, so a decision that waited for it would let the
        // next sweep say the same thing again.
        $claimed = NotificationDigest::query()
            ->where('workspace_id', $workspace->id)
            ->where('dedupe_key', $key)
            ->where('last_sent_at', '>', $silentUntil)
            ->doesntExist();

        if (! $claimed) {
            return 0;
        }

        $digest = NotificationDigest::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'dedupe_key' => $key,
        ]);

        $digest->last_sent_at = now();
        $digest->times_sent = $digest->times_sent + 1;
        $digest->save();

        $notification->sendToWorkspace($workspace);

        return 1;
    }

    private function coolingOffDays(): int
    {
        return max(1, (int) config('notifications.cooling_off_days', 14));
    }
}
