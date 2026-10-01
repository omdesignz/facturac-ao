<?php

namespace App\Models;

use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property string $code
 * @property string $name
 * @property string $address_line
 * @property string|null $municipality
 * @property string $province_code
 * @property string $timezone
 * @property bool $is_head_office
 * @property bool $is_active
 * @property-read LegalEntity $legalEntity
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'code',
    'name',
    'address_line',
    'municipality',
    'province_code',
    'timezone',
    'is_head_office',
    'is_active',
])]
class Establishment extends Model
{
    /** @use HasFactory<EstablishmentFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /**
     * @return array<int, string>
     */
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

    /** @return HasMany<FiscalSeries, $this> */
    public function fiscalSeries(): HasMany
    {
        return $this->hasMany(FiscalSeries::class);
    }

    /** @return HasMany<FiscalDocument, $this> */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /** @return HasMany<TransportDocument, $this> */
    public function transportDocuments(): HasMany
    {
        return $this->hasMany(TransportDocument::class);
    }

    /** @return HasMany<Quote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** @return HasMany<RecurringInvoice, $this> */
    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Makes this the head office, standing the previous one down.
     *
     * One transaction because it is one decision: a moment with two head
     * offices, or none, is a moment where the AGT payload has no answer for
     * which address the company trades from.
     */
    public function makeHeadOffice(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('legal_entity_id', $this->legal_entity_id)
                ->whereKeyNot($this->getKey())
                ->where('is_head_office', true)
                ->update(['is_head_office' => false]);

            $this->forceFill(['is_head_office' => true, 'is_active' => true])->save();
        });
    }

    /**
     * Whether anything already points here.
     *
     * A place that has issued documents, holds stock or owns a series cannot be
     * deleted — the documents must keep resolving where they were issued.
     */
    public function isReferenced(): bool
    {
        return $this->fiscalDocuments()->exists()
            || $this->transportDocuments()->exists()
            || $this->fiscalSeries()->exists()
            || $this->quotes()->exists()
            || $this->recurringInvoices()->exists()
            || $this->stockLevels()->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('establishment')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
