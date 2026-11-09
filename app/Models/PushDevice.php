<?php

namespace App\Models;

use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A signed-in phone reachable through Apple or Firebase.
 *
 * @property int $id
 * @property int $user_id
 * @property int $personal_access_token_id
 * @property string $token
 * @property string $provider
 * @property string|null $bundle_id
 * @property string|null $binding
 * @property string|null $token_hash
 * @property string $environment 'sandbox' for a development build, 'production' otherwise
 */
#[Fillable(['user_id', 'personal_access_token_id', 'token', 'token_hash', 'environment', 'provider', 'bundle_id', 'binding'])]
class PushDevice extends Model
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    protected $attributes = ['provider' => 'apns'];

    protected $hidden = ['token', 'token_hash', 'binding'];

    protected static function booted(): void
    {
        static::saving(function (self $device): void {
            $device->token_hash = hash('sha256', $device->provider.':'.$device->token);
        });
    }

    public const ENVIRONMENTS = ['sandbox', 'production'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /**
     * Phones whose sign-in still works.
     *
     * An expired token stays in the table until the nightly prune, and a
     * phone nobody is signed in to should not keep hearing about the company.
     *
     * @param  Builder<PushDevice>  $query
     */
    public function scopeSignedIn(Builder $query): void
    {
        $query->whereHas('accessToken', fn (Builder $token) => $token
            ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }
}
