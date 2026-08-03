<?php

namespace App\Models;

use App\EmisPaymentReferenceStatus;
use App\Pay4AllEnvironment;
use Carbon\CarbonImmutable;
use Database\Factories\EmisPaymentReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $subscription_charge_id
 * @property string $provider
 * @property Pay4AllEnvironment $environment
 * @property string $provider_reference_id
 * @property string $entity
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency_code
 * @property EmisPaymentReferenceStatus $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $last_checked_at
 * @property string $provider_payload_sha256
 * @property array<string, mixed>|null $provider_metadata
 */
#[Fillable([
    'workspace_id',
    'subscription_charge_id',
    'provider',
    'environment',
    'provider_reference_id',
    'entity',
    'reference',
    'amount_minor',
    'currency_code',
    'status',
    'expires_at',
    'paid_at',
    'last_checked_at',
    'provider_payload_sha256',
    'provider_metadata',
])]
#[Hidden(['provider_metadata'])]
class EmisPaymentReference extends Model
{
    /** @use HasFactory<EmisPaymentReferenceFactory> */
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

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<SubscriptionCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCharge::class, 'subscription_charge_id');
    }

    /** @return HasMany<BillingPaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BillingPaymentEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Pay4AllEnvironment::class,
            'status' => EmisPaymentReferenceStatus::class,
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'provider_metadata' => 'encrypted:array',
        ];
    }
}
