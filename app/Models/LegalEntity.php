<?php

namespace App\Models;

use App\LegalEntityStatus;
use App\TaxRegime;
use Carbon\CarbonImmutable;
use Database\Factories\LegalEntityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string|null $tax_identification_number
 * @property TaxRegime|null $tax_regime
 * @property string|null $main_cae_code
 * @property LegalEntityStatus $status
 * @property string $country_code
 * @property string $currency_code
 * @property string|null $logo_path
 * @property string $timezone
 * @property CarbonImmutable|null $onboarding_completed_at
 */
#[Fillable([
    'workspace_id',
    'legal_name',
    'trade_name',
    'tax_identification_number',
    'tax_regime',
    'main_cae_code',
    'status',
    'country_code',
    'currency_code',
    'logo_path',
    'timezone',
    'onboarding_completed_at',
])]
class LegalEntity extends Model
{
    /** @use HasFactory<LegalEntityFactory> */
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

    /** @return HasMany<Establishment, $this> */
    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<CatalogueItem, $this> */
    public function catalogueItems(): HasMany
    {
        return $this->hasMany(CatalogueItem::class);
    }

    /** @return HasMany<DataImport, $this> */
    public function dataImports(): HasMany
    {
        return $this->hasMany(DataImport::class);
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

    /** @return HasMany<AgtConnection, $this> */
    public function agtConnections(): HasMany
    {
        return $this->hasMany(AgtConnection::class);
    }

    /** @return HasMany<FiscalSeries, $this> */
    public function fiscalSeries(): HasMany
    {
        return $this->hasMany(FiscalSeries::class);
    }

    /** @return HasMany<TransportDocumentSequence, $this> */
    public function transportDocumentSequences(): HasMany
    {
        return $this->hasMany(TransportDocumentSequence::class);
    }

    /** @return HasMany<AgtSubmission, $this> */
    public function agtSubmissions(): HasMany
    {
        return $this->hasMany(AgtSubmission::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('legal-entity')
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
            'tax_regime' => TaxRegime::class,
            'status' => LegalEntityStatus::class,
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }
}
