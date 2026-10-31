<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\NotificationTopic;

class DocumentOpenedByCustomer extends WorkspaceNotification
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly string $customerName,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromDocument(FiscalDocument $document): self
    {
        return new self(
            documentPublicId: $document->public_id,
            documentNo: (string) $document->document_no,
            customerName: $document->customer_name,
            workspace: $document->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::DocumentOpened;
    }

    public function title(): string
    {
        return "{$this->customerName} abriu {$this->documentNo}";
    }

    public function body(): string
    {
        return 'Abriu o documento pela ligação que lhe enviou por email.';
    }

    public function url(): ?string
    {
        return route('documents.index');
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    /** One notice per document, however many times it is opened afterwards. */
    public function dedupeKey(): ?string
    {
        return "document_opened:{$this->documentPublicId}";
    }
}
