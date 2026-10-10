<?php

namespace App\Fiscal;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class AssistantBudget
{
    /** Counter identity is stable across browser sessions, workspaces and request spellings. */
    public function consume(AssistantInteractionContext $context): void
    {
        $cache = Cache::store((string) config('integrations.cache_store'));
        $store = $cache->getStore();
        abort_unless($store instanceof DatabaseStore, 503);
        $now = time();
        $buckets = [
            ['user-minute:'.$context->execution->actorId, 60, 6],
            ['user-day:'.$context->execution->actorId, 86400, 120],
            ['workspace-minute:'.$context->execution->workspaceId, 60, 30],
        ];
        $blocked = false;
        $retry = 1;
        foreach ($buckets as [$identity, $seconds, $limit]) {
            $key = 'assistant-quota:'.hash('sha256', $identity).':'.intdiv($now, $seconds);
            $guard = $store->lock($key.':lock', 3);
            abort_unless($guard->get(), 429, '', ['Retry-After' => '1']);
            try {
                $ttl = $seconds - $now % $seconds;
                $cache->add($key, 0, $ttl + 1);
                $count = $cache->increment($key);
                abort_unless(is_int($count), 503);
                if ($count > $limit) {
                    $blocked = true;
                    $retry = max($retry, $ttl);
                }
            } finally {
                $guard->release();
            }
        }
        abort_if($blocked, 429, '', ['Retry-After' => (string) $retry]);
    }

    public function admit(AssistantInteractionContext $context, AssistantInput $input): Lock
    {
        $cache = Cache::store((string) config('integrations.cache_store'));
        $store = $cache->getStore();
        abort_unless($store instanceof DatabaseStore, 503);
        $lock = $store->lock('assistant-user:'.$context->execution->actorId, 45);
        abort_unless($lock->get(), 409);
        try {
            $key = 'assistant-nonce:'.hash('sha256', $context->execution->actorId.':'.$input->nonce);
            abort_unless($cache->add($key, ['outcome' => 'admitted'], 600), 409);
        } catch (\Throwable $error) {
            $lock->release();
            throw $error;
        }

        return $lock;
    }
}
