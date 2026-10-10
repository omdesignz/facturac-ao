<?php

namespace App\Fiscal;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IntegrationRateLimiter
{
    /** @param array<string, int> $buckets */
    public function consume(array $buckets): void
    {
        $cache = Cache::store((string) config('integrations.cache_store'));
        $store = $cache->getStore();
        if (! $store instanceof DatabaseStore) {
            throw new HttpException(503);
        }
        $now = time();
        $window = intdiv($now, 60);
        $retry = 60 - $now % 60;
        $blocked = false;
        foreach ($buckets as $identity => $limit) {
            $key = 'external-read:'.hash('sha256', $identity).':'.$window;
            $count = $store->lock($key.':lock', 10)->block(3, function () use ($cache, $key, $retry): int {
                $cache->add($key, 0, $retry + 1);
                $count = $cache->increment($key);
                if (! is_int($count)) {
                    throw new HttpException(503);
                }

                return $count;
            });
            $blocked = $blocked || $count > $limit;
        }
        if ($blocked) {
            throw new HttpException(429, headers: ['Retry-After' => (string) $retry]);
        }
    }
}
