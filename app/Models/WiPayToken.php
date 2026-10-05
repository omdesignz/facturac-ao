<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WiPayTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $client_fingerprint
 * @property string $token_fingerprint
 * @property string $scope
 * @property string $access_token
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['client_fingerprint', 'token_fingerprint', 'scope', 'access_token', 'expires_at'])]
#[Hidden(['access_token', 'client_fingerprint', 'token_fingerprint'])]
class WiPayToken extends Model
{
    /** @use HasFactory<WiPayTokenFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'expires_at' => 'immutable_datetime'];
    }
}
