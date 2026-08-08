<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\Models\RecurringInvoice;
use App\NotificationTopic;

class RecurringInvoiceGenerated extends WorkspaceNotification
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $profileName,
        public readonly string $customerName,
        public readonly int $grossTotalMinor,
        public readonly string $currencyCode,
        public readonly bool $wasIssued,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromDocument(RecurringInvoice $profile, FiscalDocument $document): self
    {
        return new self(
            documentPublicId: $document->public_id,
            profileName: $profile->name,
            customerName: $document->customer_name,
            grossTotalMinor: $document->gross_total_minor,
            currencyCode: $document->currency_code,
            wasIssued: $document->document_no !== null,
            workspace: $document->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RecurringGenerated;
    }

    public function title(): string
    {
        return $this->wasIssued
            ? "Avença {$this->profileName} emitida"
            : "Avença {$this->profileName} deixou um rascunho";
    }

    public function body(): string
    {
        $amount = number_format($this->grossTotalMinor / 100, 2, ',', ' ');
        $tail = $this->wasIssued
            ? 'Já seguiu para a AGT.'
            : 'Reveja antes de emitir.';

        return "{$this->customerName} · {$amount} {$this->currencyCode}. {$tail}";
    }

    public function url(): ?string
    {
        return $this->wasIssued
            ? route('agt.submissions.index', ['document' => $this->documentPublicId])
            : route('invoices.edit', $this->documentPublicId);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }
}
