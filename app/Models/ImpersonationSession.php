<?php

namespace App\Models;

use Database\Factories\ImpersonationSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single troubleshooting session, from the moment support stepped into an
 * account to the moment they stepped back out.
 *
 * @property int $id
 * @property string $public_id
 * @property int $impersonator_id
 * @property int $subject_id
 * @property int|null $workspace_id
 * @property string $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $subject_notified_at
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $ended_by
 * @property int $write_count
 * @property int $blocked_count
 */
#[Fillable([
    'impersonator_id',
    'subject_id',
    'workspace_id',
    'reason',
    'ip_address',
    'user_agent',
    'started_at',
])]
class ImpersonationSession extends Model
{
    /** @use HasFactory<ImpersonationSessionFactory> */
    use HasFactory, HasUlids;

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

    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /** Seconds left before the server closes this session on its own. */
    public function remainingSeconds(): int
    {
        $deadline = $this->started_at->addMinutes(
            (int) config('impersonation.max_minutes'),
        );

        return max(0, $deadline->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'subject_notified_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'write_count' => 'integer',
            'blocked_count' => 'integer',
        ];
    }
}
