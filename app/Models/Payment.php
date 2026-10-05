<?php

namespace App\Models;

use App\PaymentEnvironment;
use App\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $provider
 * @property PaymentEnvironment $environment
 * @property string $client_fingerprint
 * @property string|null $provider_payment_id
 * @property string $payable_type
 * @property int $payable_id
 * @property int $amount_minor
 * @property string $currency_code
 * @property string $customer_identifier
 * @property string|null $signature_token
 * @property string|null $checkout_url
 * @property PaymentStatus $status
 * @property string|null $failure_code
 * @property CarbonImmutable|null $request_started_at
 * @property CarbonImmutable|null $retry_after_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property-read Model $payable
 */
#[Fillable(['workspace_id', 'provider', 'environment', 'client_fingerprint', 'payable_type', 'payable_id', 'amount_minor', 'currency_code', 'customer_identifier', 'status'])]
#[Hidden(['customer_identifier', 'signature_token', 'checkout_url', 'client_fingerprint'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    protected $attributes = ['provider' => 'wipay', 'status' => 'created', 'currency_code' => 'AOA'];

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            if ($payment->isDirty(['public_id', 'workspace_id', 'provider', 'environment', 'client_fingerprint', 'payable_type', 'payable_id', 'amount_minor', 'currency_code', 'customer_identifier'])) {
                throw new DomainException('Os dados de uma tentativa de pagamento são imutáveis.');
            }
        });
    }

    /** @return list<string> */
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

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => PaymentEnvironment::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'customer_identifier' => 'encrypted',
            'signature_token' => 'encrypted',
            'checkout_url' => 'encrypted',
            'request_started_at' => 'immutable_datetime',
            'retry_after_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }
}
