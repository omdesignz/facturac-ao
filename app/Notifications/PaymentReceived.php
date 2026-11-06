<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\NotificationTopic;

/**
 * A customer paid: someone in the company issued a receipt for it.
 *
 * Told to everyone else who works there, so the owner hears that money came in
 * without having to ask whoever took it. The person who recorded it already knows.
 */
class PaymentReceived extends WorkspaceNotification
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly string $customerName,
        public readonly int $amountMinor,
        public readonly string $currencyCode,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromReceipt(FiscalDocument $receipt): self
    {
        return new self(
            documentPublicId: $receipt->public_id,
            documentNo: (string) $receipt->document_no,
            customerName: $receipt->customer_name,
            amountMinor: $receipt->gross_total_minor,
            currencyCode: $receipt->currency_code,
            workspace: $receipt->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::PaymentReceived;
    }

    public function title(): string
    {
        return 'Pagamento recebido';
    }

    public function body(): string
    {
        $amount = number_format($this->amountMinor / 100, 2, ',', ' ');
        $currency = $this->currencyCode === 'AOA' ? 'Kz' : $this->currencyCode;

        return "{$this->customerName} pagou {$amount} {$currency}. Recibo {$this->documentNo} emitido.";
    }

    public function url(): ?string
    {
        return route('documents.index', ['documento' => $this->documentPublicId]);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    /** @return array{type: 'document', public_id: string, label: string} */
    public function pushTarget(): array
    {
        return ['type' => 'document', 'public_id' => $this->documentPublicId, 'label' => $this->documentNo];
    }

    public function dedupeKey(): ?string
    {
        return "payment_received:{$this->documentPublicId}";
    }
}
