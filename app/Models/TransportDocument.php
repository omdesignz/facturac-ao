<?php

namespace App\Models;

use App\TransportDocumentStatus;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Database\Factories\TransportDocumentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The immutable legal record accompanying a physical movement of goods.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $establishment_id
 * @property int|null $customer_id
 * @property int|null $transport_document_sequence_id
 * @property int $created_by_user_id
 * @property int $updated_by_user_id
 * @property int|null $issued_by_user_id
 * @property int|null $cancelled_by_user_id
 * @property TransportDocumentType $document_type
 * @property TransportDocumentStatus $status
 * @property string|null $document_no
 * @property int|null $issue_sequence
 * @property int $revision
 * @property CarbonImmutable $movement_date
 * @property CarbonImmutable $movement_start_at
 * @property CarbonImmutable|null $movement_end_at
 * @property string $recipient_name
 * @property string $recipient_tax_identification_number
 * @property string $recipient_country_code
 * @property string $recipient_address
 * @property string $recipient_city
 * @property string|null $recipient_province
 * @property string $origin_address
 * @property string $origin_city
 * @property string|null $origin_province
 * @property string $origin_country_code
 * @property string $destination_address
 * @property string $destination_city
 * @property string|null $destination_province
 * @property string $destination_country_code
 * @property string|null $transporter_name
 * @property string|null $transporter_tax_identification_number
 * @property string|null $vehicle_registration
 * @property int|null $gross_weight_grams
 * @property int|null $package_count
 * @property string $currency_code
 * @property int $net_total_minor
 * @property int $tax_payable_minor
 * @property int $gross_total_minor
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property string|null $document_hash
 * @property string|null $hash_control
 * @property CarbonImmutable|null $system_entry_at
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'customer_id',
    'transport_document_sequence_id',
    'created_by_user_id',
    'updated_by_user_id',
    'issued_by_user_id',
    'cancelled_by_user_id',
    'document_type',
    'status',
    'document_no',
    'issue_sequence',
    'revision',
    'movement_date',
    'movement_start_at',
    'movement_end_at',
    'recipient_name',
    'recipient_tax_identification_number',
    'recipient_country_code',
    'recipient_address',
    'recipient_city',
    'recipient_province',
    'origin_address',
    'origin_city',
    'origin_province',
    'origin_country_code',
    'destination_address',
    'destination_city',
    'destination_province',
    'destination_country_code',
    'transporter_name',
    'transporter_tax_identification_number',
    'vehicle_registration',
    'gross_weight_grams',
    'package_count',
    'currency_code',
    'net_total_minor',
    'tax_payable_minor',
    'gross_total_minor',
    'notes',
    'cancellation_reason',
    'document_hash',
    'hash_control',
    'software_product_id',
    'software_product_version',
    'software_validation_number',
    'system_entry_at',
    'frozen_at',
    'issued_at',
    'cancelled_at',
])]
class TransportDocument extends Model
{
    /** @use HasFactory<TransportDocumentFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $originalStatus = TransportDocumentStatus::from(
                (string) $document->getRawOriginal('status'),
            );

            if ($originalStatus === TransportDocumentStatus::Draft) {
                return;
            }

            $allowedCancellationFields = [
                'status',
                'cancelled_by_user_id',
                'cancellation_reason',
                'cancelled_at',
            ];
            $changedFields = array_keys($document->getDirty());
            $isCancellation = $originalStatus === TransportDocumentStatus::Issued
                && $document->status === TransportDocumentStatus::Cancelled
                && array_diff($changedFields, $allowedCancellationFields) === [];

            if (! $isCancellation) {
                throw new DomainException('Issued transport documents are immutable.');
            }
        });

        static::deleting(function (self $document): void {
            if (! $document->status->isMutable()) {
                throw new DomainException('Issued transport documents cannot be deleted.');
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isMutable(): bool
    {
        return $this->status->isMutable();
    }

    public function ensureMutable(): void
    {
        if (! $this->isMutable()) {
            throw new DomainException('Este documento de transporte já não pode ser alterado.');
        }
    }

    public function canCancel(): bool
    {
        return $this->status === TransportDocumentStatus::Issued;
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

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<TransportDocumentSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(TransportDocumentSequence::class, 'transport_document_sequence_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** @return HasMany<TransportDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TransportDocumentLine::class)->orderBy('line_number');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => TransportDocumentType::class,
            'status' => TransportDocumentStatus::class,
            'revision' => 'integer',
            'movement_date' => 'immutable_date',
            'movement_start_at' => 'immutable_datetime',
            'movement_end_at' => 'immutable_datetime',
            'gross_weight_grams' => 'integer',
            'package_count' => 'integer',
            'net_total_minor' => 'integer',
            'tax_payable_minor' => 'integer',
            'gross_total_minor' => 'integer',
            'system_entry_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
