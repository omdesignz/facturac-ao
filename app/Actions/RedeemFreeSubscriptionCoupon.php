<?php

namespace App\Actions;

use App\Billing\SubscriptionCouponPricer;
use App\Exceptions\BillingActionRefused;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\SubscriptionChargeStatus;
use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a plan on for the days a free-time code gives, with no payment.
 *
 * The free days are recorded as a paid charge of zero, so the payment history
 * shows them and the code's use counts like any other. A workspace already
 * paying for the same plan has the days added after its current period; one
 * paying for another plan keeps it, and the code waits.
 */
final readonly class RedeemFreeSubscriptionCoupon
{
    public function __construct(private SubscriptionCouponPricer $couponPricer) {}

    /**
     * @throws BillingActionRefused when the code does not give free time here.
     * @throws DomainException when the workspace cannot change plan right now.
     */
    public function execute(Workspace $workspace, SubscriptionPlan $plan, User $actor, string $couponCode): WorkspaceSubscription
    {
        if (! $actor->can('update', $workspace) || ! $plan->is_active) {
            throw new DomainException('Não é possível activar este plano.');
        }

        return DB::transaction(function () use ($workspace, $plan, $actor, $couponCode): WorkspaceSubscription {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $pendingCheckout = SubscriptionCharge::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('active_checkout_key')
                ->exists();

            if ($pendingCheckout) {
                throw new DomainException('Conclua ou verifique o pagamento pendente antes de usar um código de desconto.');
            }

            $quote = $this->couponPricer->quote($workspace, $plan, $couponCode, lockForUpdate: true);

            if (! $quote->isFree()) {
                throw BillingActionRefused::because('Este código dá desconto num pagamento. Continue para a WiPay para o usar.');
            }

            $subscription = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();
            $now = CarbonImmutable::now('Africa/Luanda');
            $startsAt = $now;
            $subscriptionStartsAt = $now;

            if ($subscription?->status === WorkspaceSubscriptionStatus::Active
                && $subscription->current_period_ends_at?->isFuture()) {
                if ($subscription->subscription_plan_id !== $plan->id) {
                    throw BillingActionRefused::because('Já tem outro plano activo. Use este código no plano actual ou depois de o período terminar.');
                }

                $startsAt = $subscription->current_period_ends_at;
                $subscriptionStartsAt = $subscription->current_period_started_at ?? $now;
            }

            $endsAt = $startsAt->addDays($quote->freeDays);

            $charge = SubscriptionCharge::query()->create([
                'workspace_id' => $workspace->id,
                'subscription_plan_id' => $plan->id,
                'created_by_user_id' => $actor->id,
                'checkout_token' => (string) Str::ulid(),
                'active_checkout_key' => null,
                'amount_minor' => 0,
                'currency_code' => $plan->currency_code,
                'provider_fee_basis_points' => 0,
                'estimated_provider_fee_minor' => 0,
                'status' => SubscriptionChargeStatus::Paid,
                'period_starts_at' => $startsAt,
                'period_ends_at' => $endsAt,
                'due_at' => $now,
                'paid_at' => $now,
            ]);
            $charge->couponRedemption()->create([
                'subscription_coupon_id' => $quote->coupon->id,
                'workspace_id' => $workspace->id,
                'list_amount_minor' => $quote->listAmountMinor,
                'discount_minor' => $quote->discountMinor,
            ]);

            $subscription ??= new WorkspaceSubscription(['workspace_id' => $workspace->id]);
            $subscription->fill([
                'subscription_plan_id' => $plan->id, 'status' => WorkspaceSubscriptionStatus::Active,
                'current_period_started_at' => $subscriptionStartsAt, 'current_period_ends_at' => $endsAt,
                'trial_ends_at' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null,
            ])->save();
            $charge->forceFill(['workspace_subscription_id' => $subscription->id])->save();

            return $subscription->setRelation('plan', $plan);
        }, 5);
    }
}
