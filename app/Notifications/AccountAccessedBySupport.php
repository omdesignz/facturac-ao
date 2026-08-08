<?php

namespace App\Notifications;

use App\Models\ImpersonationSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer, at the moment it happens, that support has entered their
 * account.
 *
 * Sent every time and not suppressible from the interface: an access the
 * account holder never hears about is exactly the kind the audit trail exists
 * to make impossible.
 */
class AccountAccessedBySupport extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $sessionPublicId,
        public readonly string $supportName,
        public readonly string $reason,
        public readonly string $startedAt,
        public readonly int $maxMinutes,
    ) {
        $this->afterCommit();
    }

    public static function fromModel(ImpersonationSession $session): self
    {
        return new self(
            sessionPublicId: $session->public_id,
            supportName: $session->impersonator->name,
            reason: $session->reason,
            startedAt: $session->started_at->toIso8601String(),
            maxMinutes: (int) config('impersonation.max_minutes'),
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A nossa equipa de apoio entrou na sua conta')
            ->greeting('Acesso de apoio à sua conta')
            ->line("{$this->supportName}, da equipa de apoio da ".(string) config('app.name').', entrou na sua conta para diagnosticar um problema.')
            ->line("Motivo registado: “{$this->reason}”")
            ->line("O acesso termina automaticamente ao fim de {$this->maxMinutes} minutos e fica registado por inteiro, incluindo qualquer alteração feita.")
            ->line('Emitir documentos fiscais, mexer em pagamentos e alterar as suas credenciais continuam bloqueados durante este acesso.')
            ->action('Rever a segurança da conta', route('settings.security'))
            ->line('Se não pediu ajuda nem esperava este acesso, altere a palavra-passe e fale connosco de imediato.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_accessed_by_support',
            'impersonation_session' => $this->sessionPublicId,
            'support_name' => $this->supportName,
            'reason' => $this->reason,
            'started_at' => $this->startedAt,
        ];
    }
}
