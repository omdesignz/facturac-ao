<?php

namespace App\Models;

use App\SubscriptionChargeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $workspace_subscription_id
 * @property int $subscription_plan_id
 * @property int|null $created_by_user_id
 * @property string $checkout_token
 * @property string|null $active_checkout_key
 * @property int $amount_minor
 * @property string $currency_code
 * @property int $provider_fee_basis_points
 * @property int $estimated_provider_fee_minor
 * @property SubscriptionChargeStatus $status
 * @property CarbonImmutable $period_starts_at
 * @property CarbonImmutable $period_ends_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_code
 */
#[Fillable([
    'workspace_id',
    'workspace_subscription_id',
    'subscription_plan_id',
    'created_by_user_id',
    'checkout_token',
    'active_checkout_key',
    'amount_minor',
    'currency_code',
    'provider_fee_basis_points',
    'estimated_provider_fee_minor',
    'status',
    'period_starts_at',
    'period_ends_at',
    'due_at',
    'paid_at',
    'failed_at',
    'failure_code',
])]
class SubscriptionCharge extends Model
{
    /** @use HasFactory<SubscriptionChargeFactory> */
    use HasFactory, HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<WorkspaceSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSubscription::class, 'workspace_subscription_id');
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasOne<EmisPaymentReference, $this> */
    public function paymentReference(): HasOne
    {
        return $this->hasOne(EmisPaymentReference::class);
    }

    /** @return MorphOne<Payment, $this> */
    public function hostedPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionChargeStatus::class,
            'provider_fee_basis_points' => 'integer',
            'estimated_provider_fee_minor' => 'integer',
            'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
