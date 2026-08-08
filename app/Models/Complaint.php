<?php

namespace App\Models;

use App\ComplaintCategory;
use App\ComplaintStatus;
use Database\Factories\ComplaintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * One entry in the complaints book.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int|null $user_id
 * @property int|null $workspace_id
 * @property ComplaintCategory $category
 * @property ComplaintStatus $status
 * @property string $subject
 * @property string $body
 * @property string $contact_name
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string|null $resolution
 * @property int|null $handled_by_user_id
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property Carbon $response_due_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'category',
    'status',
    'subject',
    'body',
    'contact_name',
    'contact_email',
    'contact_phone',
    'response_due_at',
    'ip_address',
])]
class Complaint extends Model
{
    /** @use HasFactory<ComplaintFactory> */
    use HasFactory, HasUlids, Notifiable;

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
     * Mail goes to the address given on the form, which is not necessarily the
     * account's: whoever complained is who needs the reference number.
     */
    public function routeNotificationForMail(): string
    {
        return $this->contact_email;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeStillOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (ComplaintStatus $status): string => $status->value,
            ComplaintStatus::openStates(),
        ));
    }

    /** Past the deadline we committed to and still unanswered. */
    public function isOverdue(): bool
    {
        return ! $this->status->isClosed() && $this->response_due_at->isPast();
    }

    /**
     * A short reference the customer can quote on the phone, or to INADEC.
     *
     * Sequential within the year rather than random: "REC-2026-0007" is a
     * number someone can read aloud without mistakes.
     */
    public static function nextReference(): string
    {
        $year = now()->year;
        $count = self::query()->whereYear('created_at', $year)->count();

        return sprintf('REC-%d-%04d', $year, $count + 1);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ComplaintCategory::class,
            'status' => ComplaintStatus::class,
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'response_due_at' => 'immutable_datetime',
        ];
    }
}
