<?php

namespace App\Models;

use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A named set of prices — the tabela a group of customers buys on.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property string $name
 * @property string|null $description
 * @property string $currency_code
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'name',
    'description',
    'currency_code',
    'is_default',
    'is_active',
])]
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
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

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Makes this the list new customers join, standing down the previous one.
     *
     * Both writes happen in one transaction because they are one decision: a
     * moment with two defaults would hand an arbitrary price to whichever
     * query happened to run first.
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('legal_entity_id', $this->legal_entity_id)
                ->whereKeyNot($this->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('price-list')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
