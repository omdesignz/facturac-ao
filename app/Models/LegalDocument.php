<?php

namespace App\Models;

use App\LegalDocumentType;
use Database\Factories\LegalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of one legal document.
 *
 * @property int $id
 * @property string $public_id
 * @property LegalDocumentType $type
 * @property int $version
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property Carbon|null $effective_at
 * @property Carbon|null $published_at
 * @property int|null $published_by_user_id
 */
#[Fillable(['type', 'version', 'title', 'summary', 'body', 'effective_at'])]
class LegalDocument extends Model
{
    /** @use HasFactory<LegalDocumentFactory> */
    use HasFactory, HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('effective_at', '<=', now());
    }

    /**
     * The version in force right now, or null if nothing has been published.
     *
     * A version dated in the future is written but not yet binding, so it stays
     * out of the customer's way until the day it takes effect.
     */
    public static function current(LegalDocumentType $type): ?self
    {
        return self::query()
            ->where('type', $type)
            ->published()
            ->orderByDesc('version')
            ->first();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isInForce(): bool
    {
        return $this->isPublished()
            && $this->effective_at !== null
            && $this->effective_at->lessThanOrEqualTo(now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LegalDocumentType::class,
            'version' => 'integer',
            'effective_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
