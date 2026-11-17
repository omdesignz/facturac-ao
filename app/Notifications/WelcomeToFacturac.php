<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        return (new MailMessage)
            ->subject('Bem-vindo ao facturac.ao')
            ->greeting('A sua conta já tem casa.')
            ->line('O seu email está confirmado. Já pode começar a preparar o espaço da sua empresa no facturac.ao.')
            ->line('**1. Complete os dados da empresa.** Confira o NIF, a morada e o regime de IVA.')
            ->line('**2. Proteja a sua conta.** Active a autenticação de dois factores em [Definições → Segurança]('.route('settings.security').').')
            ->line('**3. Prepare a facturação.** Siga os passos de configuração e confirme a ligação à AGT antes de emitir documentos reais.')
            ->action('Preparar a minha empresa', route('onboarding'))
            ->line('Precisa de ajuda para começar? Responda a este email. A nossa equipa acompanha-o.');
    }
}
