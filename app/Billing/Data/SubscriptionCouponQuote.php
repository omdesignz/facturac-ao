<?php

namespace App\Billing\Data;

use App\Models\SubscriptionCoupon;

/**
 * What a plan costs once a discount code is applied, in minor units. A code
 * that gives free days costs nothing and lasts `freeDays` instead of the
 * plan's own period.
 */
final readonly class SubscriptionCouponQuote
{
    public function __construct(
        public SubscriptionCoupon $coupon,
        public int $listAmountMinor,
        public int $discountMinor,
        public int $freeDays = 0,
    ) {}

    public function isFree(): bool
    {
        return $this->freeDays > 0;
    }

    public function amountMinor(): int
    {
        return $this->listAmountMinor - $this->discountMinor;
    }

    /** @return array{code: string, description: string|null, list_amount_minor: int, discount_minor: int, amount_minor: int, free_days: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->coupon->code,
            'description' => $this->coupon->description,
            'list_amount_minor' => $this->listAmountMinor,
            'discount_minor' => $this->discountMinor,
            'amount_minor' => $this->amountMinor(),
            'free_days' => $this->freeDays,
        ];
    }
}
