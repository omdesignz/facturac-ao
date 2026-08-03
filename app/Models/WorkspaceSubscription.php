<?php

namespace App\Models;

use App\WorkspaceSubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $subscription_plan_id
 * @property WorkspaceSubscriptionStatus $status
 * @property CarbonImmutable|null $current_period_started_at
 * @property CarbonImmutable|null $current_period_ends_at
 * @property CarbonImmutable|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'workspace_id',
    'subscription_plan_id',
    'status',
    'current_period_started_at',
    'current_period_ends_at',
    'trial_ends_at',
    'cancel_at_period_end',
    'cancelled_at',
])]
class WorkspaceSubscription extends Model
{
    /** @use HasFactory<WorkspaceSubscriptionFactory> */
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

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** @return HasMany<SubscriptionCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WorkspaceSubscriptionStatus::class,
            'current_period_started_at' => 'immutable_datetime',
            'current_period_ends_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
