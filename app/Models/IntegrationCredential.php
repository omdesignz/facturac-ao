<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $integration_id
 * @property string $secret_hash
 * @property string $hash_version
 * @property string $creator_attribution_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 */
class IntegrationCredential extends Model
{
    /** @use HasFactory<IntegrationCredentialFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['public_id', 'integration_id', 'secret_hash', 'hash_version', 'created_by_user_id', 'creator_principal_kind', 'creator_attribution_id', 'expires_at', 'revoked_at', 'revoked_by_user_id', 'revocation_reason', 'replaces_credential_id', 'last_used_at'];

    protected $hidden = ['secret_hash', 'hash_version', 'creator_attribution_id', 'creator_principal_kind', 'created_by_user_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['public_id', 'integration_id', 'secret_hash', 'hash_version', 'creator_principal_kind', 'creator_attribution_id', 'replaces_credential_id'] as $column) {
                if ($model->isDirty($column)) {
                    throw new \DomainException('Immutable credential identity.');
                }
            }
        });
    }
}
