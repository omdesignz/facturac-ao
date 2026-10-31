<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * The reminder a company sends to a customer who is late.
 *
 * The words are the company's own, reviewed by a person before they leave, so
 * this only sets them as an email: paragraphs stay paragraphs, and a list of
 * documents keeps its line breaks.
 */
class PaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
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
        $message = (new MailMessage)->subject($this->subject);

        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            // A mail line collapses its own line breaks, which would flatten a
            // list of documents into one sentence; escaped, with <br>, it holds.
            $message->line(
                preg_match('/\R/', $paragraph) === 1
                    ? new HtmlString(nl2br(e($paragraph), false))
                    : $paragraph,
            );
        }

        return $message->line(
            'Em caso de dúvida, responda a este email ou contacte '.PlatformSetting::get('support_email').'.',
        );
    }
}
