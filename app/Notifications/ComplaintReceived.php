<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Confirms a complaint was entered in the book, with its reference and the date
 * an answer is owed by.
 */
class ComplaintReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly string $subject,
        public readonly string $categoryLabel,
        public readonly string $responseDueAt,
    ) {
        $this->afterCommit();
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
        $due = Carbon::parse($this->responseDueAt)->translatedFormat('d \d\e F \d\e Y');
        $authority = (string) config('platform.consumer_authority.name');
        $authorityUrl = (string) config('platform.consumer_authority.url');

        return (new MailMessage)
            ->subject("Reclamação {$this->reference} registada")
            ->greeting('Recebemos a sua reclamação')
            ->line("Referência: **{$this->reference}**")
            ->line("Assunto: {$this->subject} ({$this->categoryLabel})")
            ->line("Comprometemo-nos a responder até {$due}.")
            ->line('Guarde esta referência: precisa dela para acompanhar o caso connosco.')
            ->line("Se a nossa resposta não o satisfizer, pode recorrer ao {$authority} — {$authorityUrl}")
            ->line('Para acrescentar informação, responda a este email ou escreva para '.PlatformSetting::get('complaints_email').'.');
    }
}
