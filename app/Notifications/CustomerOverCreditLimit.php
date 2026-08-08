<?php

namespace App\Notifications;

use App\Models\Customer;
use App\NotificationTopic;
use Illuminate\Notifications\Messages\MailMessage;

class CustomerOverCreditLimit extends WorkspaceNotification
{
    public function __construct(
        public readonly string $customerPublicId,
        public readonly string $customerName,
        public readonly int $outstandingMinor,
        public readonly int $limitMinor,
        public readonly string $currencyCode,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromCustomer(Customer $customer, int $outstandingMinor): self
    {
        return new self(
            customerPublicId: $customer->public_id,
            customerName: $customer->name,
            outstandingMinor: $outstandingMinor,
            limitMinor: (int) $customer->credit_limit_minor,
            currencyCode: $customer->legalEntity->currency_code,
            workspace: $customer->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::CreditLimitExceeded;
    }

    public function title(): string
    {
        return "{$this->customerName} passou o limite de crédito";
    }

    public function body(): string
    {
        $owed = number_format($this->outstandingMinor / 100, 2, ',', ' ');
        $limit = number_format($this->limitMinor / 100, 2, ',', ' ');

        return "Deve {$owed} {$this->currencyCode} contra um limite de {$limit} {$this->currencyCode}.";
    }

    public function url(): ?string
    {
        return route('customers.show', $this->customerPublicId);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function dedupeKey(): ?string
    {
        return "credit_limit:{$this->customerPublicId}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Limite de crédito ultrapassado · {$this->customerName}")
            ->greeting('Um cliente passou o limite acordado')
            ->line($this->body())
            ->action('Ver o cliente', (string) $this->url());
    }
}
