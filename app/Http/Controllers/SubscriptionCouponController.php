<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionCouponRequest;
use App\Models\SubscriptionCoupon;
use App\Models\SubscriptionCouponRedemption;
use App\SubscriptionCouponDiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets support staff hand out discount codes for the subscription plans.
 * A code that was ever applied to a charge is only switched off, never deleted.
 */
class SubscriptionCouponController extends Controller
{
    public function index(): Response
    {
        $coupons = SubscriptionCoupon::query()
            ->withCount([
                'redemptions',
                'redemptions as used_count' => fn (Builder $redemptions) => $redemptions->whereHas(
                    'charge',
                    fn (Builder $charges) => $charges->whereIn('status', SubscriptionCouponRedemption::HOLDING_STATUSES),
                ),
            ])
            ->latest('id')
            ->get();

        return Inertia::render('Support/Coupons', [
            'coupons' => $coupons->map(fn (SubscriptionCoupon $coupon): array => $this->present($coupon))->values()->all(),
            'discountTypes' => array_map(fn (SubscriptionCouponDiscountType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], SubscriptionCouponDiscountType::cases()),
            'currencies' => array_values((array) config('fiscal.currencies', ['AOA'])),
            'maxFreeDays' => SubscriptionCoupon::maximumFreeDays(),
        ]);
    }

    public function store(StoreSubscriptionCouponRequest $request): RedirectResponse
    {
        SubscriptionCoupon::query()->create([
            ...$request->couponData(),
            'created_by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'Código de desconto criado.');
    }

    public function update(StoreSubscriptionCouponRequest $request, SubscriptionCoupon $coupon): RedirectResponse
    {
        $coupon->update($request->couponData());

        return back()->with('success', 'Código de desconto actualizado.');
    }

    public function destroy(SubscriptionCoupon $coupon): RedirectResponse
    {
        if ($coupon->redemptions()->exists()) {
            return back()->with('error', 'Este código já foi usado num pagamento. Desactive-o em vez de o apagar.');
        }

        $coupon->delete();

        return back()->with('success', 'Código de desconto apagado.');
    }

    /** @return array<string, mixed> */
    private function present(SubscriptionCoupon $coupon): array
    {
        return [
            'public_id' => $coupon->public_id,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'discount_type' => $coupon->discount_type->value,
            'percent_off' => $coupon->percent_off,
            'amount_off_minor' => $coupon->amount_off_minor,
            'free_days' => $coupon->free_days,
            'currency_code' => $coupon->currency_code,
            'max_redemptions' => $coupon->max_redemptions,
            'used_count' => (int) $coupon->getAttribute('used_count'),
            'expires_on' => $coupon->expires_at?->setTimezone('Africa/Luanda')->toDateString(),
            'is_active' => $coupon->is_active,
            'is_expired' => $coupon->hasExpired(),
            'can_delete' => (int) $coupon->getAttribute('redemptions_count') === 0,
            'created_at' => $coupon->created_at?->toIso8601String(),
        ];
    }
}
