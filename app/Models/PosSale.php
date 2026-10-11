<?php

namespace App\Models;

use App\PaymentMethod;
use Database\Factories\PosSaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a shift to the Factura/Recibo it produced.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $pos_session_id
 * @property int $fiscal_document_id
 * @property string $client_key
 * @property PaymentMethod $payment_method
 * @property int $total_minor
 * @property int $tendered_minor
 * @property int $change_minor
 * @property int $created_by_user_id
 * @property-read PosSession $session
 * @property-read FiscalDocument $fiscalDocument
 * @property-read User $createdBy
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'pos_session_id',
    'fiscal_document_id',
    'client_key',
    'payment_method',
    'total_minor',
    'tendered_minor',
    'change_minor',
    'created_by_user_id',
])]
class PosSale extends Model
{
    /** @use HasFactory<PosSaleFactory> */
    use HasFactory, HasUlids;

    /** Not a column: whether this instance is a replay of an earlier request. */
    public bool $replayed = false;

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

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'total_minor' => 'integer',
            'tendered_minor' => 'integer',
            'change_minor' => 'integer',
        ];
    }
}
