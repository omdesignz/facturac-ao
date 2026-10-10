<?php

namespace App\Models;

use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Database\Eloquent\Model;

/** Read-only presentation model; persistence is exclusively the bounded service. */
class TenantAiCredential extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $visible = ['id', 'connection_id', 'state', 'verification_state', 'created_at', 'expires_at'];

    protected $hidden = ['secret_ciphertext', 'wrapped_dek', 'kek_version', 'encryption_schema', 'wrap_revision'];

    protected $attributes = ['state' => 'pending', 'verification_state' => 'unverified', 'encryption_schema' => 1, 'wrap_revision' => 1];

    protected static function booted(): void
    {
        static::saving(fn () => throw new TenantAiStorageUnavailable);
        static::deleting(fn () => throw new TenantAiStorageUnavailable);
    }

    /**
     * Prevent framework cloners from inspecting encrypted attributes or their originals.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setRawAttributes(array $attributes, mixed $sync = false): static
    {
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->protectDebugValue($key, $value);
        }

        return parent::setRawAttributes($attributes, $sync);
    }

    public function setAttribute(mixed $key, mixed $value): mixed
    {
        return parent::setAttribute($key, $this->protectDebugValue($key, $value));
    }

    private function protectDebugValue(mixed $key, mixed $value): mixed
    {
        return in_array($key, $this->hidden, true) && $value !== null && ! $value instanceof \SensitiveParameterValue
            ? new \SensitiveParameterValue($value)
            : $value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credential' => 'redacted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
