<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\NotificationTopic;

class DocumentAcceptedByAgt extends WorkspaceNotification
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromDocument(FiscalDocument $document): self
    {
        return new self(
            documentPublicId: $document->public_id,
            documentNo: (string) $document->document_no,
            workspace: $document->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::DocumentAccepted;
    }

    public function title(): string
    {
        return "{$this->documentNo} aceite pela AGT";
    }

    public function body(): string
    {
        return 'A comunicação ficou validada. Não há nada a fazer.';
    }

    public function url(): ?string
    {
        return route('agt.submissions.index', ['document' => $this->documentPublicId]);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }
}
