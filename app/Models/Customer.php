<?php

namespace App\Models;

use App\WithholdingType;
use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property string $name
 * @property string $tax_identification_number
 * @property string $country_code
 * @property string|null $address_line
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_active
 * @property int $payment_terms_days
 * @property int|null $credit_limit_minor
 * @property int|null $price_list_id
 * @property bool $auto_send_documents
 * @property WithholdingType|null $withholding_type
 * @property int|null $withholding_rate_basis_points
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'name',
    'tax_identification_number',
    'country_code',
    'address_line',
    'email',
    'phone',
    'is_active',
    'payment_terms_days',
    'credit_limit_minor',
    'price_list_id',
    'auto_send_documents',
    'withholding_type',
    'withholding_rate_basis_points',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
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

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return HasMany<FiscalDocument, $this> */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /** @return HasMany<TransportDocument, $this> */
    public function transportDocuments(): HasMany
    {
        return $this->hasMany(TransportDocument::class);
    }

    /** @return HasMany<CustomerPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(CustomerPrice::class);
    }

    /**
     * The tabela this customer buys on.
     *
     * Separate from `prices()` and less specific than it: the list is what a
     * group of customers shares, an entry in `prices()` is what was agreed with
     * this one buyer alone.
     *
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * When an invoice issued today would fall due.
     *
     * Zero-day terms mean payment on delivery, so the document falls due the
     * day it is issued rather than carrying no date at all — an invoice with no
     * due date can never be overdue, which is the wrong answer for cash terms.
     */
    public function dueDateFor(CarbonInterface $issuedOn): CarbonInterface
    {
        return $issuedOn->copy()->addDays($this->payment_terms_days);
    }

    public function hasCreditLimit(): bool
    {
        return $this->credit_limit_minor !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_send_documents' => 'boolean',
            'payment_terms_days' => 'integer',
            'credit_limit_minor' => 'integer',
            'withholding_type' => WithholdingType::class,
            'withholding_rate_basis_points' => 'integer',
        ];
    }
}
