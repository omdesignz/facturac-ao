<?php

namespace App\Notifications;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\NotificationTopic;
use Carbon\CarbonImmutable;

/**
 * The eve of an avença's run: what it will raise tomorrow at six, so someone
 * can skip it this once or raise it now. Ignored, tomorrow runs as always.
 */
class RecurringInvoiceDue extends WorkspaceNotification
{
    public function __construct(
        public readonly string $profilePublicId,
        public readonly string $profileName,
        public readonly string $customerName,
        public readonly int $estimatedTotalMinor,
        public readonly string $currencyCode,
        public readonly string $runOn,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromProfile(RecurringInvoice $profile, int $estimatedTotalMinor): self
    {
        return new self(
            profilePublicId: $profile->public_id,
            profileName: $profile->name,
            customerName: $profile->customer->name,
            estimatedTotalMinor: $estimatedTotalMinor,
            currencyCode: $profile->legalEntity->currency_code,
            runOn: $profile->next_run_on->toDateString(),
            workspace: $profile->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RecurringDue;
    }

    public function title(): string
    {
        return "Amanhã: avença {$this->profileName}";
    }

    public function body(): string
    {
        $whole = number_format(intdiv($this->estimatedTotalMinor, 100), 0, '', ' ');
        $cents = $this->estimatedTotalMinor % 100;
        $amount = ($cents === 0 ? $whole : $whole.','.str_pad((string) $cents, 2, '0', STR_PAD_LEFT))
            .' '.($this->currencyCode === 'AOA' ? 'Kz' : $this->currencyCode);

        return "{$this->customerName} · {$amount}, ".CarbonImmutable::parse($this->runOn)->format('d/m')
            .'. Pode saltar desta vez ou gerar já.';
    }

    public function url(): ?string
    {
        return route('recurring.index');
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function dedupeKey(): ?string
    {
        return "recurring_due:{$this->profilePublicId}:{$this->runOn}";
    }

    /** @return array{type: 'recurring', public_id: string, label: string} */
    public function pushTarget(): array
    {
        return ['type' => 'recurring', 'public_id' => $this->profilePublicId, 'label' => $this->profileName];
    }

    /** The phone offers the two choices on the notification itself; each opens the avença to confirm. */
    public function toPush(User $notifiable): array
    {
        $push = parent::toPush($notifiable);
        $push['aps']['category'] = 'RECURRING_DUE';

        return [...$push, 'run_on' => $this->runOn];
    }
}
