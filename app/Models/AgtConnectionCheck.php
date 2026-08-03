<?php

namespace App\Models;

use App\AgtConnectionCheckStatus;
use App\AgtOperation;
use Carbon\CarbonImmutable;
use Database\Factories\AgtConnectionCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $agt_connection_id
 * @property AgtOperation $operation
 * @property AgtConnectionCheckStatus $status
 * @property string $probe_uuid
 * @property string $endpoint_path
 * @property string|null $request_body_sha256
 * @property string|null $response_body_sha256
 * @property int|null $http_status
 * @property string|null $result_code
 * @property list<string>|null $error_codes
 * @property string $safe_message
 * @property int|null $duration_ms
 * @property int $attempt_count
 * @property int|null $requested_by_user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'agt_connection_id',
    'operation',
    'status',
    'probe_uuid',
    'endpoint_path',
    'request_body_sha256',
    'response_body_sha256',
    'http_status',
    'result_code',
    'error_codes',
    'safe_message',
    'duration_ms',
    'attempt_count',
    'requested_by_user_id',
    'started_at',
    'completed_at',
])]
class AgtConnectionCheck extends Model
{
    /** @use HasFactory<AgtConnectionCheckFactory> */
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

    /** @return BelongsTo<AgtConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AgtConnection::class, 'agt_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation' => AgtOperation::class,
            'status' => AgtConnectionCheckStatus::class,
            'error_codes' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
