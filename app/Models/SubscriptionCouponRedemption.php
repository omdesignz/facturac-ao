<?php

namespace App\Models;

use App\SubscriptionChargeStatus;
use Database\Factories\SubscriptionCouponRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount code applied to one subscription charge, with the list price and
 * discount frozen as they were when the charge was created.
 *
 * @property int $id
 * @property int $subscription_coupon_id
 * @property int $workspace_id
 * @property int $subscription_charge_id
 * @property int $list_amount_minor
 * @property int $discount_minor
 */
#[Fillable([
    'subscription_coupon_id',
    'workspace_id',
    'subscription_charge_id',
    'list_amount_minor',
    'discount_minor',
])]
class SubscriptionCouponRedemption extends Model
{
    /** @use HasFactory<SubscriptionCouponRedemptionFactory> */
    use HasFactory;

    /**
     * Charge statuses under which a use still counts. Failed, expired and
     * cancelled payments give the use back.
     */
    public const HOLDING_STATUSES = [
        SubscriptionChargeStatus::Creating,
        SubscriptionChargeStatus::Pending,
        SubscriptionChargeStatus::Review,
        SubscriptionChargeStatus::Paid,
    ];

    /** @return BelongsTo<SubscriptionCoupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCoupon::class, 'subscription_coupon_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<SubscriptionCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCharge::class, 'subscription_charge_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'list_amount_minor' => 'integer',
            'discount_minor' => 'integer',
        ];
    }
}
