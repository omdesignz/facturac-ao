<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * An override for one entry in config('platform.settings').
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by_user_id
 */
#[Fillable(['key', 'value', 'updated_by_user_id'])]
class PlatformSetting extends Model
{
    private const CACHE_KEY = 'platform-settings';

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Every setting, with stored values layered over the config defaults.
     *
     * @return array<string, string>
     */
    public static function values(): array
    {
        /** @var array<string, string> $defaults */
        $defaults = config('platform.settings', []);

        /** @var array<string, string> $stored */
        $stored = Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => self::query()->pluck('value', 'key')->all(),
        );

        // A blank override falls back to the default rather than showing the
        // customer an empty phone number.
        $overrides = array_filter(
            $stored,
            fn (string $value): bool => trim($value) !== '',
        );

        return [...$defaults, ...array_intersect_key($overrides, $defaults)];
    }

    public static function get(string $key): string
    {
        return self::values()[$key] ?? '';
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function put(array $values, ?User $editor = null): void
    {
        /** @var array<string, string> $defaults */
        $defaults = config('platform.settings', []);

        foreach (array_intersect_key($values, $defaults) as $key => $value) {
            self::query()->updateOrCreate(
                ['key' => $key],
                ['value' => trim($value), 'updated_by_user_id' => $editor?->id],
            );
        }

        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(self::flush(...));
        static::deleted(self::flush(...));
    }
}
