<?php

namespace App\Models;

use App\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A proposal to a customer, before anything fiscal happens.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property QuoteStatus $status
 * @property int|null $customer_id
 * @property string $customer_name
 * @property Carbon $issue_date
 * @property Carbon $valid_until
 * @property string $currency_code
 * @property int $net_total_minor
 * @property int $tax_total_minor
 * @property int $gross_total_minor
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property int|null $converted_document_id
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'customer_id',
    'created_by_user_id',
    'status',
    'customer_name',
    'customer_tax_identification_number',
    'customer_country_code',
    'customer_address',
    'issue_date',
    'valid_until',
    'currency_code',
    'notes',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
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

    /** @return HasMany<QuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function convertedDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'converted_document_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (QuoteStatus $status): string => $status->value,
            QuoteStatus::open(),
        ));
    }

    /**
     * Past its validity without having been decided.
     *
     * Derived rather than stored so a quote becomes stale on the right day
     * without depending on a scheduled job having run.
     */
    public function hasLapsed(): bool
    {
        return ! $this->status->isClosed()
            && $this->status !== QuoteStatus::Accepted
            && $this->valid_until->isPast();
    }

    /** The status to show, accounting for validity having run out. */
    public function effectiveStatus(): QuoteStatus
    {
        return $this->hasLapsed() ? QuoteStatus::Expired : $this->status;
    }

    /**
     * The next quotable reference for the year, e.g. ORC 2026/0007.
     *
     * Sequential rather than random because it gets read down a phone.
     */
    public static function nextReference(LegalEntity $legalEntity, int $year): string
    {
        $count = self::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereYear('issue_date', $year)
            ->count();

        return sprintf('ORC %d/%04d', $year, $count + 1);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'issue_date' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'net_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_total_minor' => 'integer',
        ];
    }
}
