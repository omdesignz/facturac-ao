<?php

namespace App\Models;

use App\AgtSubmissionOperation;
use App\AgtSubmissionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AgtSubmissionFactory;
use DomainException;
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
 * @property string $submission_uuid
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property int $agt_connection_id
 * @property AgtSubmissionOperation $operation
 * @property string $schema_version
 * @property AgtSubmissionStatus $status
 * @property string|null $request_id
 * @property string $request_body
 * @property string $request_body_sha256
 * @property string|null $last_response_body_sha256
 * @property int|null $last_http_status
 * @property string|null $last_result_code
 * @property list<string>|null $last_error_codes
 * @property string $safe_message
 * @property int $attempt_count
 * @property int $attempts_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 */
#[Fillable([
    'submission_uuid',
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'agt_connection_id',
    'operation',
    'schema_version',
    'status',
    'request_id',
    'request_body',
    'request_body_sha256',
    'last_response_body_sha256',
    'last_http_status',
    'last_result_code',
    'last_error_codes',
    'safe_message',
    'attempt_count',
    'next_attempt_at',
    'submitted_at',
    'received_at',
    'completed_at',
    'failed_at',
])]
#[Hidden(['request_body'])]
class AgtSubmission extends Model
{
    /** @use HasFactory<AgtSubmissionFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $submission): void {
            if ($submission->isDirty([
                'submission_uuid',
                'workspace_id',
                'legal_entity_id',
                'fiscal_document_id',
                'agt_connection_id',
                'operation',
                'schema_version',
                'request_body',
                'request_body_sha256',
            ])) {
                throw new DomainException('AGT submission identity and bytes are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('AGT submission evidence cannot be deleted.');
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

    /** @return BelongsTo<AgtConnection, $this> */
    public function agtConnection(): BelongsTo
    {
        return $this->belongsTo(AgtConnection::class);
    }

    /** @return HasMany<AgtSubmissionAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AgtSubmissionAttempt::class)->oldest('attempt_number');
    }

    /** @return HasMany<FiscalDocumentEvent, $this> */
    public function documentEvents(): HasMany
    {
        return $this->hasMany(FiscalDocumentEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation' => AgtSubmissionOperation::class,
            'status' => AgtSubmissionStatus::class,
            'request_body' => 'encrypted',
            'last_error_codes' => 'array',
            'next_attempt_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
