<?php

namespace App\Billing;

use App\Billing\Data\SubscriptionCouponQuote;
use App\Exceptions\BillingActionRefused;
use App\Models\SubscriptionCoupon;
use App\Models\SubscriptionPlan;
use App\Models\Workspace;

/**
 * Decides whether a discount code applies to a plan for a workspace, and what
 * the plan then costs. The checkout asks again inside its own transaction,
 * with the code locked, so a preview never promises what the charge refuses.
 */
final class SubscriptionCouponPricer
{
    /** The smallest charge WiPay will create, in minor units. */
    public const MINIMUM_CHARGE_MINOR = 100;

    /** @throws BillingActionRefused */
    public function quote(Workspace $workspace, SubscriptionPlan $plan, string $code, bool $lockForUpdate = false): SubscriptionCouponQuote
    {
        $coupon = SubscriptionCoupon::query()
            ->where('code', SubscriptionCoupon::normaliseCode($code))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw BillingActionRefused::because('Este código não existe ou já não está disponível.');
        }

        if ($coupon->hasExpired()) {
            throw BillingActionRefused::because('Este código já expirou.');
        }

        if ($coupon->heldRedemptions()->where('workspace_id', $workspace->id)->exists()) {
            throw BillingActionRefused::because('Esta empresa já usou este código.');
        }

        if ($coupon->max_redemptions !== null && $coupon->heldRedemptions()->count() >= $coupon->max_redemptions) {
            throw BillingActionRefused::because('Este código já atingiu o limite de utilizações.');
        }

        $discount = $coupon->discountFor($plan->amount_minor);

        if ($coupon->currency_code !== $plan->currency_code || $discount <= 0
            || $plan->amount_minor - $discount < self::MINIMUM_CHARGE_MINOR) {
            throw BillingActionRefused::because('Este código não se aplica a este plano.');
        }

        return new SubscriptionCouponQuote($coupon, $plan->amount_minor, $discount);
    }
}
