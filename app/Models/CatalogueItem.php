<?php

namespace App\Models;

use App\CatalogueItemType;
use Database\Factories\CatalogueItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property string $code
 * @property CatalogueItemType $type
 * @property string $name
 * @property string|null $description
 * @property string $unit_of_measure
 * @property int $unit_price_minor
 * @property string $currency_code
 * @property string $tax_type
 * @property string|null $tax_code
 * @property string $tax_percentage
 * @property string|null $tax_exemption_code
 * @property bool $is_active
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'code',
    'type',
    'name',
    'description',
    'unit_of_measure',
    'unit_price_minor',
    'currency_code',
    'tax_type',
    'tax_code',
    'tax_percentage',
    'tax_exemption_code',
    'is_active',
])]
class CatalogueItem extends Model
{
    /** @use HasFactory<CatalogueItemFactory> */
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

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('catalogue-item')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CatalogueItemType::class,
            'unit_price_minor' => 'integer',
            'tax_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
