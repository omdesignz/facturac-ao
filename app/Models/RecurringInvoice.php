<?php

namespace App\Models;

use App\FiscalDocumentType;
use App\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing arrangement for a continuous service.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property FiscalDocumentType $document_type
 * @property RecurrenceFrequency $frequency
 * @property bool $is_active
 * @property bool $auto_issue
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable $next_run_on
 * @property CarbonImmutable|null $last_run_at
 * @property int $generated_count
 * @property list<array<string, mixed>> $lines
 * @property string|null $notes
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'customer_id',
    'created_by_user_id',
    'name',
    'document_type',
    'frequency',
    'is_active',
    'auto_issue',
    'starts_on',
    'ends_on',
    'next_run_on',
    'lines',
    'notes',
])]
class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

    /**
     * Profiles that are due to run.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query, ?CarbonInterface $on = null): void
    {
        $on ??= now('Africa/Luanda')->startOfDay();

        $query->where('is_active', true)
            ->whereDate('next_run_on', '<=', $on)
            ->where(function (Builder $nested) use ($on): void {
                $nested->whereNull('ends_on')->orWhereDate('ends_on', '>=', $on);
            });
    }

    /** Whether the arrangement has run past its end date. */
    public function hasFinished(): bool
    {
        return $this->ends_on !== null
            && $this->next_run_on->greaterThan($this->ends_on);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => FiscalDocumentType::class,
            'frequency' => RecurrenceFrequency::class,
            'is_active' => 'boolean',
            'auto_issue' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'next_run_on' => 'immutable_date',
            'last_run_at' => 'immutable_datetime',
            'lines' => 'array',
            'generated_count' => 'integer',
        ];
    }
}
