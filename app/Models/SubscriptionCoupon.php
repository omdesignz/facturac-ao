<?php

namespace App\Models;

use App\SubscriptionCouponDiscountType;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionCouponFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A discount code for facturac.ao's own subscription plans, created by support
 * staff and typed by a customer on "Plano e cobrança" before paying.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string|null $description
 * @property SubscriptionCouponDiscountType $discount_type
 * @property int|null $percent_off
 * @property int|null $amount_off_minor
 * @property int|null $free_days
 * @property string $currency_code
 * @property int|null $max_redemptions
 * @property CarbonImmutable|null $expires_at
 * @property bool $is_active
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'code',
    'description',
    'discount_type',
    'percent_off',
    'amount_off_minor',
    'free_days',
    'currency_code',
    'max_redemptions',
    'expires_at',
    'is_active',
    'created_by_user_id',
])]
class SubscriptionCoupon extends Model
{
    /** @use HasFactory<SubscriptionCouponFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Codes are compared upper-case and without surrounding spaces. */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<SubscriptionCouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(SubscriptionCouponRedemption::class);
    }

    /**
     * The uses that still count against the limit: their payment is being
     * prepared, awaited, checked or paid.
     *
     * @return HasMany<SubscriptionCouponRedemption, $this>
     */
    public function heldRedemptions(): HasMany
    {
        return $this->redemptions()->whereHas(
            'charge',
            fn (Builder $charges) => $charges->whereIn('status', SubscriptionCouponRedemption::HOLDING_STATUSES),
        );
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isAfter($at ?? CarbonImmutable::now());
    }

    /** The most free days any code may give, from config. */
    public static function maximumFreeDays(): int
    {
        return max(1, (int) config('billing.coupons.max_free_days', 30));
    }

    public function givesFreeDays(): bool
    {
        return $this->discount_type === SubscriptionCouponDiscountType::FreeDays;
    }

    /**
     * The days of free access this code gives, held to today's ceiling: lowering
     * the setting also shortens codes created before it was lowered.
     */
    public function grantedFreeDays(): int
    {
        return $this->givesFreeDays() ? max(0, min((int) $this->free_days, self::maximumFreeDays())) : 0;
    }

    /**
     * The discount on a price, in minor units, never more than the price.
     * A percentage rounds down, so the customer never pays a fraction of a
     * cêntimo more than the plan states minus the advertised percent.
     */
    public function discountFor(int $amountMinor): int
    {
        $discount = match ($this->discount_type) {
            SubscriptionCouponDiscountType::Percentage => intdiv($amountMinor * (int) $this->percent_off, 100),
            SubscriptionCouponDiscountType::Fixed => (int) $this->amount_off_minor,
            SubscriptionCouponDiscountType::FreeDays => $amountMinor,
        };

        return max(0, min($discount, $amountMinor));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subscription-coupon')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => SubscriptionCouponDiscountType::class,
            'percent_off' => 'integer',
            'amount_off_minor' => 'integer',
            'free_days' => 'integer',
            'max_redemptions' => 'integer',
            'expires_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
