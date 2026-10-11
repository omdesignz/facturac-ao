<?php

namespace App\Models;

use App\PosCashMovementType;
use Database\Factories\PosCashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money put in or taken out of the drawer during a shift.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $pos_session_id
 * @property PosCashMovementType $type
 * @property int $amount_minor
 * @property string $reason
 * @property int $created_by_user_id
 * @property-read PosSession $session
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'pos_session_id',
    'type',
    'amount_minor',
    'reason',
    'created_by_user_id',
])]
class PosCashMovement extends Model
{
    /** @use HasFactory<PosCashMovementFactory> */
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

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PosCashMovementType::class,
            'amount_minor' => 'integer',
        ];
    }
}
