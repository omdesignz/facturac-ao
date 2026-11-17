<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class WelcomeToFacturac extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = Str::of((string) ($notifiable->name ?? ''))->trim()->before(' ')->toString();

        return (new MailMessage)
            ->subject('Boas-vindas ao facturac.ao')
            ->greeting($firstName === '' ? 'Olá,' : "Olá, {$firstName}.")
            ->line('O seu email está confirmado e a conta está aberta. Para emitir a primeira factura, faltam três passos:')
            ->line($this->steps())
            ->action('Preparar a minha empresa', route('onboarding'))
            ->line('Alguma dúvida pelo caminho? Responda a este email; quem o lê é a nossa equipa.');
    }

    /**
     * The steps as one Markdown list, so they read as a sequence rather than
     * three loose paragraphs. A plain line would have its line breaks folded
     * into spaces.
     */
    private function steps(): HtmlString
    {
        return new HtmlString(implode("\n", [
            '1. **Os dados da empresa.** Confirme o NIF, a morada e o regime de IVA.',
            '2. **A ligação à AGT.** Siga a configuração e confirme a ligação antes de emitir documentos reais.',
            '3. **A segurança da conta.** Active os dois factores em [Definições → Segurança]('.route('settings.security').').',
        ]));
    }
}
