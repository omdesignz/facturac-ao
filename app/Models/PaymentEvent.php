<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentEventFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string $event_type
 * @property string $event_key
 * @property string $payload_sha256
 * @property array<string, mixed>|null $safe_context
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['payment_id', 'event_type', 'event_key', 'payload_sha256', 'safe_context', 'occurred_at'])]
class PaymentEvent extends Model
{
    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Os eventos de pagamento são evidência imutável.');
        });
        static::deleting(function (): never {
            throw new DomainException('Os eventos de pagamento não podem ser eliminados.');
        });
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['safe_context' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
