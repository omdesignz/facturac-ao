<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\NotificationTopic;
use Illuminate\Notifications\Messages\MailMessage;

class InvoiceFellOverdue extends WorkspaceNotification
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly string $customerName,
        public readonly int $outstandingMinor,
        public readonly int $daysPastDue,
        public readonly string $currencyCode,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromDocument(FiscalDocument $document, int $outstandingMinor, int $daysPastDue): self
    {
        return new self(
            documentPublicId: $document->public_id,
            documentNo: (string) $document->document_no,
            customerName: $document->customer_name,
            outstandingMinor: $outstandingMinor,
            daysPastDue: $daysPastDue,
            currencyCode: $document->currency_code,
            workspace: $document->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::InvoiceOverdue;
    }

    public function title(): string
    {
        return "{$this->documentNo} venceu há {$this->daysPastDue} dia(s)";
    }

    public function body(): string
    {
        $amount = number_format($this->outstandingMinor / 100, 2, ',', ' ');

        return "{$this->customerName} tem {$amount} {$this->currencyCode} por pagar.";
    }

    public function url(): ?string
    {
        return route('debts.index');
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    /** One notice per document, however many mornings the sweep runs. */
    public function dedupeKey(): ?string
    {
        return "invoice_overdue:{$this->documentPublicId}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Factura vencida · {$this->documentNo}")
            ->greeting('Uma factura passou o vencimento')
            ->line($this->title().'.')
            ->line($this->body())
            ->action('Ver dívidas', (string) $this->url());
    }
}
