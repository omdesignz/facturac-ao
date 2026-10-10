<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property string $environment
 * @property int|null $sponsor_user_id
 * @property int|null $sponsor_membership_id
 * @property int $revision
 * @property string $creator_attribution_id
 * @property CarbonImmutable|null $revoked_at
 */
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['public_id', 'workspace_id', 'legal_entity_id', 'environment', 'name', 'sponsor_user_id', 'sponsor_membership_id', 'creator_principal_kind', 'creator_attribution_id', 'revision', 'revoked_at', 'revoked_by_user_id', 'revocation_reason'];

    protected $hidden = ['sponsor_user_id', 'sponsor_membership_id', 'creator_attribution_id', 'creator_principal_kind'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['public_id', 'workspace_id', 'legal_entity_id', 'environment', 'creator_principal_kind', 'creator_attribution_id'] as $column) {
                if ($model->isDirty($column)) {
                    throw new \DomainException('Immutable integration identity.');
                }
            }
        });
    }
}
