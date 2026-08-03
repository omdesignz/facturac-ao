<?php

namespace App\Models;

use App\SubscriptionInterval;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $summary
 * @property int $amount_minor
 * @property string $currency_code
 * @property SubscriptionInterval $interval
 * @property int $trial_days
 * @property list<string>|null $features
 * @property array<string, int|string|bool>|null $limits
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable([
    'code',
    'name',
    'summary',
    'amount_minor',
    'currency_code',
    'interval',
    'trial_days',
    'features',
    'limits',
    'is_active',
    'sort_order',
])]
class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
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

    /** @return HasMany<WorkspaceSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(WorkspaceSubscription::class);
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
            'interval' => SubscriptionInterval::class,
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
