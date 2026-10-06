<?php

namespace App\Models;

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\FiscalDocumentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $establishment_id
 * @property int|null $customer_id
 * @property int|null $references_document_id
 * @property string|null $references_document_no
 * @property string|null $adjustment_reason
 * @property PaymentMethod|null $payment_method
 * @property int|null $payment_amount_minor
 * @property CarbonImmutable|null $payment_date
 * @property int|null $fiscal_series_id
 * @property int|null $agt_connection_id
 * @property int $created_by_user_id
 * @property int $updated_by_user_id
 * @property int|null $issued_by_user_id
 * @property FiscalDocumentType $document_type
 * @property FiscalDocumentStatus $status
 * @property string $agt_document_status
 * @property string|null $document_no
 * @property int|null $issue_sequence
 * @property CarbonImmutable $document_date
 * @property CarbonImmutable|null $due_date
 * @property string $currency_code
 * @property int $exchange_rate_micro
 * @property string $customer_name
 * @property string $customer_tax_identification_number
 * @property string $customer_country_code
 * @property string|null $customer_address
 * @property string|null $notes
 * @property int $settlement_total_minor
 * @property int $net_total_minor
 * @property int $tax_payable_minor
 * @property int $gross_total_minor
 * @property int $revision
 * @property string $payload_schema_version
 * @property string $calculation_sha256
 * @property string|null $signable_payload_sha256
 * @property string|null $document_payload_sha256
 * @property string|null $document_jws
 * @property string|null $software_product_id
 * @property string|null $software_product_version
 * @property string|null $software_validation_number
 * @property string|null $software_key_fingerprint
 * @property string|null $taxpayer_key_fingerprint
 * @property CarbonImmutable|null $system_entry_at
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $sent_to_customer_at
 * @property string|null $sent_to_email
 * @property int $send_count
 * @property-read LegalEntity $legalEntity
 * @property-read Establishment $establishment
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'establishment_id',
    'customer_id',
    'references_document_id',
    'references_document_no',
    'adjustment_reason',
    'payment_method',
    'payment_amount_minor',
    'payment_date',
    'fiscal_series_id',
    'agt_connection_id',
    'created_by_user_id',
    'updated_by_user_id',
    'issued_by_user_id',
    'document_type',
    'status',
    'agt_document_status',
    'document_no',
    'issue_sequence',
    'document_date',
    'due_date',
    'currency_code',
    'exchange_rate_micro',
    'customer_name',
    'customer_tax_identification_number',
    'customer_country_code',
    'customer_address',
    'notes',
    'settlement_total_minor',
    'net_total_minor',
    'tax_payable_minor',
    'gross_total_minor',
    'revision',
    'payload_schema_version',
    'calculation_sha256',
    'signable_payload_sha256',
    'document_payload_sha256',
    'document_jws',
    'software_product_id',
    'software_product_version',
    'software_validation_number',
    'software_key_fingerprint',
    'taxpayer_key_fingerprint',
    'system_entry_at',
    'frozen_at',
    'issued_at',
])]
#[Hidden(['document_jws'])]
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $document->ensureOriginalVersionIsMutable();
        });

        static::deleting(function (self $document): void {
            $document->ensureOriginalVersionIsMutable();
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
            throw new DomainException('Issued fiscal documents are immutable.');
        }
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
     * How the document prints: the frozen layout, issuer and logo, and the
     * fingerprint of what it shows. Made when the document is issued.
     *
     * @return HasOne<FiscalDocumentPrint, $this>
     */
    public function printRecord(): HasOne
    {
        return $this->hasOne(FiscalDocumentPrint::class);
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** @return BelongsTo<Customer, $this> */
    /**
     * The document this one corrects. Only set for adjustment documents.
     *
     * @return BelongsTo<FiscalDocument, $this>
     */
    public function referencesDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'references_document_id');
    }

    /**
     * Invoices this receipt pays off.
     *
     * @return HasMany<FiscalDocumentSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(FiscalDocumentSettlement::class, 'fiscal_document_id');
    }

    /**
     * Receipts that have paid against this invoice.
     *
     * @return HasMany<FiscalDocumentSettlement, $this>
     */
    public function settledBy(): HasMany
    {
        return $this->hasMany(FiscalDocumentSettlement::class, 'settled_document_id');
    }

    /**
     * Tax the buyer keeps back on this document and pays to the AGT themselves.
     *
     * @return HasMany<FiscalDocumentWithholding, $this>
     */
    public function withholdings(): HasMany
    {
        return $this->hasMany(FiscalDocumentWithholding::class, 'fiscal_document_id');
    }

    /** @return HasMany<FiscalDocument, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class, 'references_document_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<FiscalSeries, $this> */
    public function fiscalSeries(): BelongsTo
    {
        return $this->belongsTo(FiscalSeries::class);
    }

    /** @return BelongsTo<AgtConnection, $this> */
    public function agtConnection(): BelongsTo
    {
        return $this->belongsTo(AgtConnection::class);
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

    /** @return HasMany<FiscalDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FiscalDocumentLine::class)->orderBy('line_number');
    }

    /** @return HasMany<AgtSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AgtSubmission::class);
    }

    /** @return HasMany<FiscalDocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FiscalDocumentEvent::class)->oldest('occurred_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => FiscalDocumentType::class,
            'payment_method' => PaymentMethod::class,
            'payment_date' => 'immutable_date',
            'status' => FiscalDocumentStatus::class,
            'document_jws' => 'encrypted',
            'document_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'system_entry_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'sent_to_customer_at' => 'immutable_datetime',
            'exchange_rate_micro' => 'integer',
        ];
    }

    /**
     * Whether this document was written in a currency other than the kwanza.
     *
     * The stored totals are in the document's own currency. Only the audit file
     * and the printed currency block need them restated, so the conversion is
     * done where it is asked for rather than kept as a second set of columns
     * that could drift out of step with the first.
     */
    public function isForeignCurrency(): bool
    {
        return $this->exchange_rate_micro !== 1_000_000;
    }

    private function ensureOriginalVersionIsMutable(): void
    {
        $originalStatus = FiscalDocumentStatus::tryFrom(
            (string) $this->getRawOriginal('status'),
        );

        if ($originalStatus !== FiscalDocumentStatus::Draft) {
            throw new DomainException('Issued fiscal documents are immutable.');
        }
    }
}
