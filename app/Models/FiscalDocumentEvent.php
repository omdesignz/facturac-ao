<?php

namespace App\Models;

use App\FiscalDocumentEventType;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property int|null $agt_submission_id
 * @property int|null $actor_user_id
 * @property FiscalDocumentEventType $event_type
 * @property string|null $agt_document_status
 * @property array<string, mixed>|null $safe_context
 * @property CarbonImmutable $occurred_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'agt_submission_id',
    'actor_user_id',
    'event_type',
    'agt_document_status',
    'safe_context',
    'occurred_at',
])]
class FiscalDocumentEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Fiscal document events are append-only evidence.');
        });

        static::deleting(function (): never {
            throw new DomainException('Fiscal document events cannot be deleted.');
        });
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

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return BelongsTo<AgtSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AgtSubmission::class, 'agt_submission_id');
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
            'event_type' => FiscalDocumentEventType::class,
            'safe_context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
