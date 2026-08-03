<?php

namespace App\Models;

use App\AgtSubmissionAttemptOperation;
use Carbon\CarbonImmutable;
use Database\Factories\AgtSubmissionAttemptFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $agt_submission_id
 * @property AgtSubmissionAttemptOperation $operation
 * @property int $attempt_number
 * @property string $endpoint_path
 * @property string $request_body
 * @property string $request_body_sha256
 * @property string|null $response_body
 * @property string|null $response_body_sha256
 * @property int|null $http_status
 * @property string|null $result_code
 * @property list<string>|null $error_codes
 * @property string $safe_message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'agt_submission_id',
    'operation',
    'attempt_number',
    'endpoint_path',
    'request_body',
    'request_body_sha256',
    'response_body',
    'response_body_sha256',
    'http_status',
    'result_code',
    'error_codes',
    'safe_message',
    'started_at',
    'completed_at',
])]
#[Hidden(['request_body', 'response_body'])]
class AgtSubmissionAttempt extends Model
{
    /** @use HasFactory<AgtSubmissionAttemptFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('AGT transmission attempts are append-only evidence.');
        });

        static::deleting(function (): never {
            throw new DomainException('AGT transmission attempts cannot be deleted.');
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

    /** @return BelongsTo<AgtSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AgtSubmission::class, 'agt_submission_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation' => AgtSubmissionAttemptOperation::class,
            'request_body' => 'encrypted',
            'response_body' => 'encrypted',
            'error_codes' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
