<?php

namespace App\Models;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use Carbon\CarbonImmutable;
use Database\Factories\AgtConnectionFactory;
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
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property AgtEnvironment $environment
 * @property string $schema_version
 * @property string|null $basic_auth_username
 * @property string|null $basic_auth_password
 * @property string|null $product_id
 * @property string|null $product_version
 * @property string|null $software_validation_number
 * @property string|null $establishment_number
 * @property string|null $software_key_reference
 * @property string|null $software_key_fingerprint
 * @property string|null $taxpayer_key_reference
 * @property string|null $taxpayer_key_fingerprint
 * @property AgtConnectionStatus $status
 * @property CarbonImmutable|null $configured_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $last_failed_at
 * @property-read LegalEntity $legalEntity
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'environment',
    'schema_version',
    'basic_auth_username',
    'basic_auth_password',
    'product_id',
    'product_version',
    'software_validation_number',
    'establishment_number',
    'software_key_reference',
    'software_key_fingerprint',
    'taxpayer_key_reference',
    'taxpayer_key_fingerprint',
    'status',
    'configured_at',
    'verified_at',
    'last_failed_at',
])]
#[Hidden(['basic_auth_username', 'basic_auth_password'])]
class AgtConnection extends Model
{
    /** @use HasFactory<AgtConnectionFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $connection): void {
            if ($connection->isDirty(['environment', 'workspace_id', 'legal_entity_id'])) {
                throw new \DomainException('AGT connection tenant and environment identity is immutable.');
            }
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

    public function hasBasicCredentials(): bool
    {
        return filled($this->basic_auth_username)
            && filled($this->basic_auth_password);
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

    /** @return HasMany<AgtConnectionCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(AgtConnectionCheck::class);
    }

    /** @return HasMany<FiscalSeries, $this> */
    public function fiscalSeries(): HasMany
    {
        return $this->hasMany(FiscalSeries::class);
    }

    /** @return HasMany<AgtSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AgtSubmission::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => AgtEnvironment::class,
            'basic_auth_username' => 'encrypted',
            'basic_auth_password' => 'encrypted',
            'status' => AgtConnectionStatus::class,
            'configured_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
        ];
    }
}
