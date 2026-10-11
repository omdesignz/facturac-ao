<?php

namespace App\Models;

use App\PosSessionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PosSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A shift on a register.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $pos_register_id
 * @property int $establishment_id
 * @property int $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property PosSessionStatus $status
 * @property string $currency_code
 * @property int $opening_float_minor
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $counted_cash_minor
 * @property int|null $expected_cash_minor
 * @property int|null $cash_difference_minor
 * @property string|null $closing_notes
 * @property array<string, mixed>|null $closing_summary
 * @property-read PosRegister $register
 * @property-read Establishment $establishment
 * @property-read User $openedBy
 * @property-read User|null $closedBy
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'pos_register_id',
    'establishment_id',
    'opened_by_user_id',
    'closed_by_user_id',
    'status',
    'currency_code',
    'opening_float_minor',
    'opened_at',
    'closed_at',
    'counted_cash_minor',
    'expected_cash_minor',
    'cash_difference_minor',
    'closing_notes',
    'closing_summary',
])]
class PosSession extends Model
{
    /** @use HasFactory<PosSessionFactory> */
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

    public function isOpen(): bool
    {
        return $this->status === PosSessionStatus::Open;
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

    /** @return BelongsTo<PosRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return HasMany<PosSale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    /** @return HasMany<PosCashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('pos-session')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PosSessionStatus::class,
            'opening_float_minor' => 'integer',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'counted_cash_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'cash_difference_minor' => 'integer',
            'closing_summary' => 'array',
        ];
    }
}
