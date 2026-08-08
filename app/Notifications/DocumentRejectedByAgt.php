<?php

namespace App\Notifications;

use App\Models\FiscalDocument;
use App\NotificationTopic;
use Illuminate\Notifications\Messages\MailMessage;

class DocumentRejectedByAgt extends WorkspaceNotification
{
    /** @param list<string> $errorCodes */
    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly int $workspace,
        public readonly array $errorCodes,
    ) {
        parent::__construct();
    }

    /** @param list<string> $errorCodes */
    public static function fromDocument(FiscalDocument $document, array $errorCodes): self
    {
        return new self(
            documentPublicId: $document->public_id,
            documentNo: (string) $document->document_no,
            workspace: $document->workspace_id,
            errorCodes: $errorCodes,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::DocumentRejected;
    }

    public function title(): string
    {
        return "{$this->documentNo} recusado pela AGT";
    }

    public function body(): string
    {
        $codes = $this->errorCodes === []
            ? 'A AGT não devolveu um código.'
            : 'Código: '.implode(', ', $this->errorCodes).'.';

        return "{$codes} Emita uma nota de correcção depois de perceber o motivo.";
    }

    public function url(): ?string
    {
        return route('agt.submissions.index', ['document' => $this->documentPublicId]);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Documento recusado pela AGT · {$this->documentNo}")
            ->greeting('A AGT recusou um documento')
            ->line($this->body())
            ->action('Ver no monitor AGT', (string) $this->url());
    }
}
