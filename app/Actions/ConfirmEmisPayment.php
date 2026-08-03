<?php

namespace App\Actions;

use App\BillingPaymentEventType;
use App\EmisPaymentReferenceStatus;
use App\Models\BillingPaymentEvent;
use App\Models\EmisPaymentReference;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceSubscription;
use App\Notifications\SubscriptionActivated;
use App\SubscriptionChargeStatus;
use App\WorkspaceRole;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ConfirmEmisPayment
{
    public function execute(
        EmisPaymentReference $paymentReference,
        string $providerEventId,
        int $amountMinor,
        string $currencyCode,
        CarbonImmutable $occurredAt,
        string $payloadSha256,
        ?User $actor = null,
    ): WorkspaceSubscription {
        $this->assertConfirmation($providerEventId, $payloadSha256);

        [$subscription, $newlyConfirmed] = DB::transaction(function () use (
            $paymentReference,
            $providerEventId,
            $amountMinor,
            $currencyCode,
            $occurredAt,
            $payloadSha256,
            $actor,
        ): array {
            $reference = EmisPaymentReference::query()
                ->with('charge.plan')
                ->whereKey($paymentReference->id)
                ->where('workspace_id', $paymentReference->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $charge = SubscriptionCharge::query()
                ->with('plan')
                ->whereKey($reference->subscription_charge_id)
                ->where('workspace_id', $reference->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingEvent = BillingPaymentEvent::query()
                ->where('provider_event_id', $providerEventId)
                ->first();

            if ($existingEvent instanceof BillingPaymentEvent) {
                return [$this->subscriptionForCharge($charge), false];
            }

            if ($reference->amount_minor !== $amountMinor
                || $reference->currency_code !== $currencyCode) {
                throw new DomainException(
                    'A confirmação não corresponde ao valor exacto da Referência EMIS.',
                );
            }

            if ($reference->status === EmisPaymentReferenceStatus::Paid) {
                $reference->events()->create([
                    'workspace_id' => $reference->workspace_id,
                    'actor_user_id' => $actor?->id,
                    'event_type' => BillingPaymentEventType::StatusChecked,
                    'provider_event_id' => $providerEventId,
                    'payload_sha256' => $payloadSha256,
                    'safe_context' => ['result' => 'duplicate_confirmation'],
                    'occurred_at' => $occurredAt,
                ]);

                return [$this->subscriptionForCharge($charge), false];
            }

            if ($reference->status !== EmisPaymentReferenceStatus::Pending
                || $charge->status !== SubscriptionChargeStatus::Pending) {
                throw new DomainException('Esta Referência EMIS já não aceita confirmação.');
            }

            $reference->forceFill([
                'status' => EmisPaymentReferenceStatus::Paid,
                'paid_at' => $occurredAt,
                'last_checked_at' => now('Africa/Luanda'),
            ])->save();
            $charge->forceFill([
                'active_checkout_key' => null,
                'status' => SubscriptionChargeStatus::Paid,
                'paid_at' => $occurredAt,
            ])->save();

            $subscription = WorkspaceSubscription::query()
                ->where('workspace_id', $charge->workspace_id)
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof WorkspaceSubscription) {
                $subscription = new WorkspaceSubscription([
                    'workspace_id' => $charge->workspace_id,
                ]);
            }

            $subscription->fill([
                'subscription_plan_id' => $charge->subscription_plan_id,
                'status' => WorkspaceSubscriptionStatus::Active,
                'current_period_started_at' => $charge->period_starts_at,
                'current_period_ends_at' => $charge->period_ends_at,
                'trial_ends_at' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
            ])->save();

            $charge->forceFill(['workspace_subscription_id' => $subscription->id])->save();

            $reference->events()->createMany([
                [
                    'workspace_id' => $reference->workspace_id,
                    'actor_user_id' => $actor?->id,
                    'event_type' => BillingPaymentEventType::PaymentConfirmed,
                    'provider_event_id' => $providerEventId,
                    'payload_sha256' => $payloadSha256,
                    'safe_context' => [
                        'amount_minor' => $amountMinor,
                        'currency_code' => $currencyCode,
                    ],
                    'occurred_at' => $occurredAt,
                ],
                [
                    'workspace_id' => $reference->workspace_id,
                    'actor_user_id' => $actor?->id,
                    'event_type' => BillingPaymentEventType::SubscriptionActivated,
                    'payload_sha256' => $payloadSha256,
                    'safe_context' => [
                        'plan_code' => $charge->plan->code,
                        'period_ends_at' => $charge->period_ends_at->toIso8601String(),
                    ],
                    'occurred_at' => $occurredAt,
                ],
            ]);

            activity('subscription-billing')
                ->causedBy($actor)
                ->performedOn($subscription)
                ->event('subscription-activated')
                ->withProperties([
                    'workspace_id' => $subscription->workspace_id,
                    'subscription_public_id' => $subscription->public_id,
                    'charge_public_id' => $charge->public_id,
                    'payment_reference_public_id' => $reference->public_id,
                    'payload_sha256' => $payloadSha256,
                ])
                ->log('Pagamento EMIS confirmado e assinatura activada.');

            return [$subscription, true];
        }, 5);

        if ($newlyConfirmed) {
            $this->notifyWorkspaceManagers($subscription, $paymentReference);
        }

        return $subscription;
    }

    private function assertConfirmation(string $providerEventId, string $payloadSha256): void
    {
        if (blank($providerEventId)) {
            throw new DomainException('A confirmação do provedor exige um identificador único.');
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $payloadSha256)) {
            throw new DomainException('A confirmação do provedor exige uma impressão SHA-256 válida.');
        }
    }

    private function subscriptionForCharge(SubscriptionCharge $charge): WorkspaceSubscription
    {
        return WorkspaceSubscription::query()
            ->where('workspace_id', $charge->workspace_id)
            ->firstOrFail();
    }

    private function notifyWorkspaceManagers(
        WorkspaceSubscription $subscription,
        EmisPaymentReference $paymentReference,
    ): void {
        $users = WorkspaceMembership::query()
            ->with('user')
            ->where('workspace_id', $subscription->workspace_id)
            ->where('is_active', true)
            ->whereIn('role', [
                WorkspaceRole::Owner->value,
                WorkspaceRole::Administrator->value,
            ])
            ->get()
            ->pluck('user')
            ->filter();

        $subscription->loadMissing('plan');
        Notification::send(
            $users,
            SubscriptionActivated::fromModels($subscription, $paymentReference),
        );
    }
}
