<?php

namespace App\Actions;

use App\Models\Payment;
use App\Models\SubscriptionCharge;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceSubscription;
use App\Notifications\SubscriptionActivated;
use App\PaymentEnvironment;
use App\PaymentStatus;
use App\SubscriptionChargeStatus;
use App\SubscriptionInterval;
use App\WorkspaceRole;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class FulfillHostedPayment
{
    public function execute(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            Workspace::query()->whereKey($payment->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->fulfilled_at !== null || ! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Rejected], true)) {
                return;
            }

            $payable = $locked->payable;

            if (! $payable instanceof SubscriptionCharge || $payable->workspace_id !== $locked->workspace_id) {
                throw new DomainException('O pagamento não corresponde à cobrança deste espaço.');
            }

            $charge = SubscriptionCharge::query()->with('plan')->whereKey($payable->id)
                ->where('workspace_id', $locked->workspace_id)->lockForUpdate()->firstOrFail();

            if ($charge->amount_minor !== $locked->amount_minor || $charge->currency_code !== $locked->currency_code) {
                throw new DomainException('O valor da cobrança não corresponde ao pagamento.');
            }

            if ($locked->environment === PaymentEnvironment::Sandbox && app()->isProduction()) {
                $locked->forceFill(['status' => PaymentStatus::Review, 'failure_code' => 'sandbox_payment_in_production'])->save();
                $charge->forceFill(['status' => SubscriptionChargeStatus::Review])->save();

                return;
            }

            if ($locked->status === PaymentStatus::Rejected) {
                $charge->forceFill(['status' => SubscriptionChargeStatus::Failed, 'active_checkout_key' => null,
                    'failed_at' => $locked->finalized_at, 'failure_code' => $locked->failure_code])->save();
                $locked->forceFill(['fulfilled_at' => now()])->save();

                return;
            }

            $subscription = WorkspaceSubscription::query()->where('workspace_id', $locked->workspace_id)->lockForUpdate()->first();
            $startsAt = CarbonImmutable::now('Africa/Luanda');
            $subscriptionStartsAt = $startsAt;

            if ($subscription?->status === WorkspaceSubscriptionStatus::Active
                && $subscription->subscription_plan_id === $charge->subscription_plan_id
                && $subscription->current_period_ends_at?->isFuture()) {
                $startsAt = $subscription->current_period_ends_at;
                $subscriptionStartsAt = $subscription->current_period_started_at ?? $subscriptionStartsAt;
            }

            $endsAt = $this->purchasedInterval($charge) === SubscriptionInterval::Monthly ? $startsAt->addMonthNoOverflow() : $startsAt->addYear();
            $subscription ??= new WorkspaceSubscription(['workspace_id' => $locked->workspace_id]);
            $subscription->fill([
                'subscription_plan_id' => $charge->subscription_plan_id, 'status' => WorkspaceSubscriptionStatus::Active,
                'current_period_started_at' => $subscriptionStartsAt, 'current_period_ends_at' => $endsAt,
                'trial_ends_at' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null,
            ])->save();
            $charge->forceFill(['workspace_subscription_id' => $subscription->id, 'status' => SubscriptionChargeStatus::Paid,
                'active_checkout_key' => null, 'paid_at' => $locked->paid_at,
                'period_starts_at' => $startsAt, 'period_ends_at' => $endsAt])->save();
            $locked->forceFill(['fulfilled_at' => now()])->save();
            $locked->events()->create([
                'event_type' => 'subscription-activated', 'event_key' => hash('sha256', 'subscription-activated'),
                'payload_sha256' => hash('sha256', 'fulfil:'.$locked->public_id),
                'safe_context' => ['plan_code' => $charge->plan->code, 'period_ends_at' => $endsAt->toIso8601String()], 'occurred_at' => now(),
            ]);
            $subscription->setRelation('plan', $charge->plan);
            $managers = WorkspaceMembership::query()->with('user')->where('workspace_id', $locked->workspace_id)
                ->where('is_active', true)->whereIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Administrator->value])
                ->get()->pluck('user')->filter();
            Notification::send($managers, new SubscriptionActivated($subscription->public_id, $charge->plan->name,
                $endsAt->toIso8601String(), $locked->public_id, 'WiPay'));
        }, 5);
    }

    /** Determine the purchased interval from frozen charge dates, not the mutable plan. */
    private function purchasedInterval(SubscriptionCharge $charge): SubscriptionInterval
    {
        return match (true) {
            $charge->period_ends_at->equalTo($charge->period_starts_at->addMonthNoOverflow()) => SubscriptionInterval::Monthly,
            $charge->period_ends_at->equalTo($charge->period_starts_at->addYear()) => SubscriptionInterval::Annual,
            default => throw new DomainException('O período comprado não corresponde a uma assinatura válida.'),
        };
    }
}
