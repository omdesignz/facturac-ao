<?php

namespace App\Billing\Data;

use App\Models\SubscriptionCoupon;

/** What a plan costs once a discount code is applied, in minor units. */
final readonly class SubscriptionCouponQuote
{
    public function __construct(
        public SubscriptionCoupon $coupon,
        public int $listAmountMinor,
        public int $discountMinor,
    ) {}

    public function amountMinor(): int
    {
        return $this->listAmountMinor - $this->discountMinor;
    }

    /** @return array{code: string, description: string|null, list_amount_minor: int, discount_minor: int, amount_minor: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->coupon->code,
            'description' => $this->coupon->description,
            'list_amount_minor' => $this->listAmountMinor,
            'discount_minor' => $this->discountMinor,
            'amount_minor' => $this->amountMinor(),
        ];
    }
}
