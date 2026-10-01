<?php

namespace App\Models;

use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One annual, type-specific numbering counter protected by a row lock.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $establishment_id
 * @property TransportDocumentType $document_type
 * @property string $series_code
 * @property int $series_year
 * @property int $next_number
 * @property int|null $last_issued_number
 * @property CarbonImmutable|null $last_movement_date
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'document_type',
    'series_code',
    'series_year',
    'next_number',
    'last_issued_number',
    'last_movement_date',
])]
class TransportDocumentSequence extends Model
{
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

    /** @return HasMany<TransportDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(TransportDocument::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => TransportDocumentType::class,
            'series_year' => 'integer',
            'next_number' => 'integer',
            'last_issued_number' => 'integer',
            'last_movement_date' => 'immutable_date',
        ];
    }
}
