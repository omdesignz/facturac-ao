<?php

namespace App\Fiscal\Documents;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<CarbonImmutable|null, CarbonImmutable|string|null> */
final class UtcEvidenceTimestamp implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:sP');
    }
}
