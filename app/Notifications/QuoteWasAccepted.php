<?php

namespace App\Notifications;

use App\Models\Quote;
use App\NotificationTopic;

class QuoteWasAccepted extends WorkspaceNotification
{
    public function __construct(
        public readonly string $quotePublicId,
        public readonly string $reference,
        public readonly string $customerName,
        public readonly int $grossTotalMinor,
        public readonly string $currencyCode,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromQuote(Quote $quote): self
    {
        return new self(
            quotePublicId: $quote->public_id,
            reference: $quote->reference,
            customerName: $quote->customer_name,
            grossTotalMinor: $quote->gross_total_minor,
            currencyCode: $quote->currency_code,
            workspace: $quote->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::QuoteAccepted;
    }

    public function title(): string
    {
        return "{$this->reference} aceite";
    }

    public function body(): string
    {
        $amount = number_format($this->grossTotalMinor / 100, 2, ',', ' ');

        return "{$this->customerName} aceitou {$amount} {$this->currencyCode}. Já pode passar a factura.";
    }

    public function url(): ?string
    {
        return route('quotes.edit', $this->quotePublicId);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }
}
