<?php

namespace App\Models;

use App\SubscriptionInterval;
use Database\Factories\SubscriptionPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What staff manage and customers compare: a name, what is included, and the
 * payment periods it can be bought for. Each enabled period is a
 * SubscriptionPlan row, so checkout and charges keep working per plan.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $summary
 * @property list<string>|null $features
 * @property array<string, int|string|bool>|null $limits
 * @property int $trial_days
 * @property string $currency_code
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $sort_order
 */
#[Fillable([
    'code',
    'name',
    'summary',
    'features',
    'limits',
    'trial_days',
    'currency_code',
    'is_active',
    'is_featured',
    'sort_order',
])]
class SubscriptionPackage extends Model
{
    /** @use HasFactory<SubscriptionPackageFactory> */
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

    /**
     * The package's plans, shortest period first.
     *
     * @return HasMany<SubscriptionPlan, $this>
     */
    public function plans(): HasMany
    {
        /*
         * Literal values, not bindings: a binding in an ORDER BY is sent along
         * when the relation is used for an UPDATE, which has no such clause.
         * Double quotes quote the column on PostgreSQL and SQLite alike.
         */
        return $this->hasMany(SubscriptionPlan::class)
            ->orderByRaw('case "interval" when \'monthly\' then 0 when \'quarterly\' then 1 when \'semiannual\' then 2 when \'annual\' then 3 else 4 end')
            ->orderBy('id');
    }

    /**
     * What the package includes, as a plain list of strings.
     *
     * Rebuilt rather than cast: the column is free-form JSON, and a package
     * backfilled from a plan recorded with keyed features would otherwise
     * reach a page as an object the list rendering cannot walk.
     *
     * @return list<string>
     */
    public function featureList(): array
    {
        $features = [];

        foreach (is_array($this->features) ? $this->features : [] as $feature) {
            $features[] = (string) $feature;
        }

        return $features;
    }

    /**
     * The packages a customer can buy right now: active, with at least one
     * active period. Only the active plans are loaded, shortest period first.
     *
     * @return Collection<int, SubscriptionPackage>
     */
    public static function offered(): Collection
    {
        return self::query()
            ->where('is_active', true)
            ->whereHas('plans', fn ($plans) => $plans->where('is_active', true))
            ->with(['plans' => fn ($plans) => $plans->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The whole percent a period saves against paying the active monthly plan
     * for the same number of months, rounded down. Null when there is no
     * active monthly plan to compare with, or when nothing is saved.
     */
    public function savingPercentage(SubscriptionPlan $plan): ?int
    {
        $monthly = $this->plans->first(
            fn (SubscriptionPlan $candidate): bool => $candidate->is_active
                && $candidate->interval === SubscriptionInterval::Monthly,
        );

        if (! $monthly instanceof SubscriptionPlan) {
            return null;
        }

        $full = $monthly->amount_minor * $plan->interval->months();

        if ($full <= 0 || $plan->amount_minor >= $full) {
            return null;
        }

        $percentage = intdiv(($full - $plan->amount_minor) * 100, $full);

        return $percentage > 0 ? $percentage : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subscription-package')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'limits' => 'array',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
