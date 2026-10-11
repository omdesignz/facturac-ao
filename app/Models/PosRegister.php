<?php

namespace App\Models;

use Database\Factories\PosRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A till at an establishment.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $establishment_id
 * @property string $name
 * @property bool $is_active
 * @property-read Establishment $establishment
 * @property-read PosSession|null $openSession
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'name',
    'is_active',
])]
class PosRegister extends Model
{
    /** @use HasFactory<PosRegisterFactory> */
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

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return HasMany<PosSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(PosSession::class);
    }

    /** @return HasOne<PosSession, $this> */
    public function openSession(): HasOne
    {
        return $this->hasOne(PosSession::class)->where('status', 'open');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('pos-register')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
