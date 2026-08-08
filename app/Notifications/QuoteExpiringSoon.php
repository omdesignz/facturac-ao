<?php

namespace App\Notifications;

use App\Models\Quote;
use App\NotificationTopic;

class QuoteExpiringSoon extends WorkspaceNotification
{
    public function __construct(
        public readonly string $quotePublicId,
        public readonly string $reference,
        public readonly string $customerName,
        public readonly int $daysLeft,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromQuote(Quote $quote, int $daysLeft): self
    {
        return new self(
            quotePublicId: $quote->public_id,
            reference: $quote->reference,
            customerName: $quote->customer_name,
            daysLeft: $daysLeft,
            workspace: $quote->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::QuoteExpiring;
    }

    public function title(): string
    {
        return $this->daysLeft === 0
            ? "{$this->reference} expira hoje"
            : "{$this->reference} expira em {$this->daysLeft} dia(s)";
    }

    public function body(): string
    {
        return "{$this->customerName} ainda não respondeu. Vale a pena ligar antes de o preço deixar de valer.";
    }

    public function url(): ?string
    {
        return route('quotes.edit', $this->quotePublicId);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function dedupeKey(): ?string
    {
        return "quote_expiring:{$this->quotePublicId}";
    }
}
