<?php

namespace App\Models;

use App\Fiscal\Documents\UtcEvidenceTimestamp;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $agt_submission_id
 * @property string $operation_uuid
 * @property int $operation_sequence
 * @property int $reducer_version
 * @property string $kind
 * @property array<string, mixed> $outcome
 * @property string $outcome_sha256
 * @property int|null $attempt_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $observed_at
 * @property CarbonImmutable $recorded_at
 * @property string $correlation_id
 */
#[Fillable(['agt_submission_id', 'workspace_id', 'legal_entity_id', 'operation_uuid', 'operation_sequence', 'kind', 'reducer_version', 'outcome', 'outcome_sha256', 'attempt_id', 'started_at', 'observed_at', 'effective_at', 'recorded_at', 'correlation_id', 'conflict_response_body', 'conflict_response_sha256'])]
#[Hidden(['outcome', 'conflict_response_body', 'conflict_response_sha256'])]
class AgtSubmissionObservation extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('AGT observations are immutable.'));
        static::deleting(fn () => throw new DomainException('AGT observations are immutable.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['outcome' => 'array', 'conflict_response_body' => 'encrypted', 'started_at' => UtcEvidenceTimestamp::class, 'observed_at' => UtcEvidenceTimestamp::class, 'effective_at' => UtcEvidenceTimestamp::class, 'recorded_at' => UtcEvidenceTimestamp::class, 'operation_sequence' => 'integer'];
    }
}
