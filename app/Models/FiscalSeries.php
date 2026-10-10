<?php

namespace App\Models;

use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\Concerns\HasFiscalEnvironment;
use Carbon\CarbonImmutable;
use Database\Factories\FiscalSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $environment
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $establishment_id
 * @property int $agt_connection_id
 * @property string $series_code
 * @property int $series_year
 * @property FiscalDocumentType $document_type
 * @property FiscalSeriesStatus $status
 * @property FiscalSeriesContingency $contingency_indicator
 * @property string $invoicing_method
 * @property CarbonImmutable|null $agt_creation_date
 * @property string $agt_first_document_no
 * @property string $agt_last_document_no
 * @property string|null $agt_first_document_created
 * @property string|null $agt_last_document_created
 * @property int $first_authorized_number
 * @property int $last_authorized_number
 * @property int $next_number
 * @property int|null $last_issued_number
 * @property CarbonImmutable|null $last_document_date
 * @property CarbonImmutable $synchronized_at
 */
#[Fillable([
    'environment',
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'agt_connection_id',
    'series_code',
    'series_year',
    'document_type',
    'status',
    'contingency_indicator',
    'invoicing_method',
    'agt_creation_date',
    'agt_first_document_no',
    'agt_last_document_no',
    'agt_first_document_created',
    'agt_last_document_created',
    'first_authorized_number',
    'last_authorized_number',
    'next_number',
    'last_issued_number',
    'last_document_date',
    'synchronized_at',
])]
class FiscalSeries extends Model
{
    /** @use HasFactory<FiscalSeriesFactory> */
    use HasFactory, HasFiscalEnvironment, HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function remainingNumbers(): int
    {
        return max(0, $this->last_authorized_number - $this->next_number + 1);
    }

    public function canAllocate(): bool
    {
        return $this->status->canAllocate()
            && $this->next_number >= $this->first_authorized_number
            && $this->next_number <= $this->last_authorized_number;
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

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<AgtConnection, $this> */
    public function agtConnection(): BelongsTo
    {
        return $this->belongsTo(AgtConnection::class);
    }

    /** @return HasMany<FiscalDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => FiscalDocumentType::class,
            'status' => FiscalSeriesStatus::class,
            'contingency_indicator' => FiscalSeriesContingency::class,
            'agt_creation_date' => 'immutable_date',
            'last_document_date' => 'immutable_date',
            'synchronized_at' => 'immutable_datetime',
        ];
    }
}
