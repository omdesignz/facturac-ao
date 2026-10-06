<?php

namespace App\Notifications;

use App\Fiscal\Documents\FiscalDocumentArchive;
use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Models\FiscalDocument;
use App\Models\PlatformSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sends an issued document to the customer it was issued to.
 *
 * The figures travel in the body rather than only behind the link: an invoice
 * whose amount can only be read by opening something else is one the customer
 * is more likely to leave for later. The link is there to be printed or saved
 * as a PDF, not to be the only way to learn what is owed.
 */
class FiscalDocumentIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $documentPublicId,
        public readonly string $documentNo,
        public readonly string $documentTypeLabel,
        public readonly string $companyName,
        public readonly string $issuedOn,
        public readonly ?string $dueOn,
        public readonly string $grossTotal,
        public readonly string $currencyCode,
        public readonly string $documentUrl,
        public readonly string $attachmentName,
    ) {
        $this->afterCommit();
    }

    /**
     * The document itself, so the customer has a copy that does not depend on
     * a link, a session, or this application still being here in five years.
     *
     * Read from the archive when the mail is built rather than carried
     * through the queue: a PDF in a serialised job is a hundred kilobytes of
     * payload per retry. The archived copy, so the attachment is byte for
     * byte the document the company sees.
     */
    private function pdf(): string
    {
        return app(FiscalDocumentArchive::class)->pdf(
            FiscalDocument::query()->where('public_id', $this->documentPublicId)->firstOrFail(),
        );
    }

    public static function fromModel(FiscalDocument $document): self
    {
        return new self(
            documentPublicId: $document->public_id,
            documentNo: (string) $document->document_no,
            documentTypeLabel: $document->document_type->label(),
            companyName: $document->legalEntity->trade_name
                ?? $document->legalEntity->legal_name,
            issuedOn: $document->document_date->toIso8601String(),
            dueOn: $document->due_date?->toIso8601String(),
            grossTotal: number_format($document->gross_total_minor / 100, 2, ',', ' '),
            currencyCode: $document->currency_code,
            // Signed and time-limited: the customer has no account, so the
            // link itself is what lets them open their own document.
            documentUrl: app(FiscalDocumentPresenter::class)->signedUrl($document),
            attachmentName: app(FiscalDocumentPdf::class)->filename($document),
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issued = Carbon::parse($this->issuedOn)->translatedFormat('d \d\e F \d\e Y');

        $message = (new MailMessage)
            ->subject("{$this->documentTypeLabel} {$this->documentNo} — {$this->companyName}")
            ->greeting("{$this->documentTypeLabel} {$this->documentNo}")
            ->line("{$this->companyName} emitiu-lhe este documento em {$issued}.")
            ->line("**Total: {$this->grossTotal} {$this->currencyCode}**");

        if ($this->dueOn !== null) {
            $due = Carbon::parse($this->dueOn)->translatedFormat('d \d\e F \d\e Y');
            $message->line("Vence a {$due}.");
        }

        return $message
            ->action('Ver o documento', $this->documentUrl)
            ->line('O documento foi comunicado à Administração Geral Tributária.')
            ->line('Em caso de dúvida sobre este documento, responda a este email ou contacte '.PlatformSetting::get('support_email').'.')
            ->attachData(
                $this->pdf(),
                $this->attachmentName,
                ['mime' => 'application/pdf'],
            );
    }
}
