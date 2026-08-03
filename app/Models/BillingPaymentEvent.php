<?php

namespace App\Models;

use App\BillingPaymentEventType;
use Carbon\CarbonImmutable;
use Database\Factories\BillingPaymentEventFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $emis_payment_reference_id
 * @property int|null $actor_user_id
 * @property BillingPaymentEventType $event_type
 * @property string|null $provider_event_id
 * @property string $payload_sha256
 * @property array<string, mixed>|null $safe_context
 * @property CarbonImmutable $occurred_at
 */
#[Fillable([
    'workspace_id',
    'emis_payment_reference_id',
    'actor_user_id',
    'event_type',
    'provider_event_id',
    'payload_sha256',
    'safe_context',
    'occurred_at',
])]
class BillingPaymentEvent extends Model
{
    /** @use HasFactory<BillingPaymentEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Billing payment events are append-only evidence.');
        });

        static::deleting(function (): never {
            throw new DomainException('Billing payment events cannot be deleted.');
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<EmisPaymentReference, $this> */
    public function paymentReference(): BelongsTo
    {
        return $this->belongsTo(EmisPaymentReference::class, 'emis_payment_reference_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => BillingPaymentEventType::class,
            'safe_context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
