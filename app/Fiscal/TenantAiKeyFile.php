<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;

/** Local, deployment-owned protected files only. No network or APP_KEY fallback. */
final class TenantAiKeyFile
{
    public function read(string $version): string
    {
        $files = config('tenant_ai.kek_files');
        if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $version) !== 1 || ! is_array($files) || ! isset($files[$version]) || ! is_string($files[$version])) {
            throw new TenantAiStorageUnavailable;
        }

        try {
            $this->assertPrivateCustodyPath($files[$version]);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }

        return $this->protectedFile($files[$version], 32, 32);
    }

    public function rejectVapCopy(#[\SensitiveParameter] string $secret): void
    {
        $reference = config('assistant.provider.secret_reference');
        if ($reference !== null) {
            if (! is_string($reference)) {
                throw new TenantAiStorageUnavailable;
            }
            $known = rtrim($this->protectedFile($reference, 16, 513), "\n");
            if (hash_equals($known, $secret)) {
                throw new TenantAiStorageUnavailable;
            }
        }
    }

    private function assertPrivateCustodyPath(string $path): void
    {
        $root = config('tenant_ai.kek_root');
        clearstatcache();
        if (! is_string($root) || ! str_starts_with($root, '/') || $root === '/'
            || realpath($root) !== $root || ! is_dir($root) || is_link($root)
            || realpath($path) !== $path || ! $this->within($path, $root)) {
            throw new TenantAiStorageUnavailable;
        }

        $served = [public_path(), base_path('public'), storage_path('app/public')];
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) === 'local'
                && (($disk['serve'] ?? false) || ($disk['visibility'] ?? null) === 'public' || isset($disk['url']))) {
                $served[] = $disk['root'] ?? null;
            }
        }
        foreach (config('filesystems.links', []) as $target) {
            $served[] = $target;
        }
        foreach ($served as $directory) {
            $canonical = $this->servedPath($directory);
            if ($root === $canonical || $this->within($root, $canonical) || $this->within($canonical, $root)) {
                throw new TenantAiStorageUnavailable;
            }
        }
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    /** Resolve existing aliases even when a configured served directory has not been created yet. */
    private function servedPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('~/(?:\.|\.\.)(?:/|$)~', $path)) {
            throw new TenantAiStorageUnavailable;
        }
        $tail = [];
        $existing = rtrim($path, '/') ?: '/';
        while (! file_exists($existing)) {
            if (is_link($existing) || $existing === '/') {
                throw new TenantAiStorageUnavailable;
            }
            array_unshift($tail, basename($existing));
            $existing = dirname($existing);
        }
        $canonical = realpath($existing);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new TenantAiStorageUnavailable;
        }

        return rtrim($canonical, '/').($tail === [] ? '' : '/'.implode('/', $tail)) ?: '/';
    }

    private function protectedFile(string $path, int $minimum, int $maximum): string
    {
        try {
            clearstatcache(true, $path);
            if (! str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)) {
                throw new TenantAiStorageUnavailable;
            }
            for ($directory = dirname($path); $directory !== '/'; $directory = dirname($directory)) {
                $stat = lstat($directory);
                if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0022) !== 0) {
                    throw new TenantAiStorageUnavailable;
                }
            }
            $before = lstat($path);
            if (! is_array($before) || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 077) !== 0 || $before['size'] < $minimum || $before['size'] > $maximum) {
                throw new TenantAiStorageUnavailable;
            }
            $handle = fopen($path, 'rb');
            if (! is_resource($handle)) {
                throw new TenantAiStorageUnavailable;
            }
            try {
                $after = fstat($handle);
                $bytes = stream_get_contents($handle, $maximum + 1);
                $current = lstat($path);
                if (! is_array($after) || ! is_array($current) || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev']
                    || $current['ino'] !== $after['ino'] || $current['dev'] !== $after['dev'] || ($after['mode'] & 077) !== 0
                    || ! is_string($bytes) || strlen($bytes) < $minimum || strlen($bytes) > $maximum) {
                    throw new TenantAiStorageUnavailable;
                }

                return $bytes;
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }
}
