<?php

namespace App\Notifications;

use App\Models\EmisPaymentReference;
use App\Models\WorkspaceSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subscriptionPublicId,
        public readonly string $planName,
        public readonly string $periodEndsAt,
        public readonly string $paymentReferencePublicId,
    ) {
        $this->afterCommit();
    }

    public static function fromModels(
        WorkspaceSubscription $subscription,
        EmisPaymentReference $paymentReference,
    ): self {
        return new self(
            subscriptionPublicId: $subscription->public_id,
            planName: $subscription->plan->name,
            periodEndsAt: $subscription->current_period_ends_at?->toIso8601String() ?? '',
            paymentReferencePublicId: $paymentReference->public_id,
        );
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Assinatura VAP Fatura activada')
            ->greeting('Pagamento confirmado')
            ->line("A assinatura do plano {$this->planName} está activa.")
            ->line('A confirmação foi recebida através da Referência EMIS e registada no histórico de cobrança.')
            ->action('Ver plano e cobrança', route('billing.show'))
            ->line('Não responda com comprovativos: o estado é confirmado directamente pelo provedor.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_activated',
            'subscription_public_id' => $this->subscriptionPublicId,
            'plan_name' => $this->planName,
            'period_ends_at' => $this->periodEndsAt,
            'payment_reference_public_id' => $this->paymentReferencePublicId,
        ];
    }
}
