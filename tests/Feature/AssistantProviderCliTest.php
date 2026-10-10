<?php

use App\Fiscal\AssistantProviderTransport;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Calls the native resolver and its real offline subprocess, without overriding transport methods. */
function resolveAssistantCli(string $sapi, string $binary, string $bindir, ?float $deadline = null): string
{
    return (new ReflectionMethod(AssistantProviderTransport::class, 'cliExecutable'))
        ->invoke(new AssistantProviderTransport, $deadline ?? hrtime(true) / 1e9 + 1, $sapi, $binary, $bindir);
}

test('FPM runtime selects and probes the trusted CLI instead of invoking the FPM binary', function () {
    config(['assistant.provider.dns_php_cli' => null]);
    $directory = sys_get_temp_dir().'/assistant-cli-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    symlink(PHP_BINARY, $directory.'/php');
    try {
        expect(resolveAssistantCli('fpm-fcgi', $directory.'/php-fpm-must-not-run', $directory))->toBe(realpath(PHP_BINARY));
    } finally {
        unlink($directory.'/php');
        rmdir($directory);
    }
});

test('protected explicit CLI path supports relocated FPM installations', function () {
    config(['assistant.provider.dns_php_cli' => PHP_BINARY]);
    expect(resolveAssistantCli('fpm-fcgi', '/unavailable/php-fpm', '/unavailable'))->toBe(realpath(PHP_BINARY));
});

test('CLI runtime is still probed rather than assumed capable', function () {
    config(['assistant.provider.dns_php_cli' => null]);
    expect(resolveAssistantCli('cli', PHP_BINARY, '/unavailable'))->toBe(realpath(PHP_BINARY));
    expect(fn () => resolveAssistantCli('cli', '/usr/bin/false', '/unavailable'))->toThrow(HttpException::class);
});

test('missing trusted CLI under FPM fails closed without PATH fallback', function () {
    config(['assistant.provider.dns_php_cli' => null]);
    expect(fn () => resolveAssistantCli('fpm-fcgi', PHP_BINARY, '/unavailable'))->toThrow(HttpException::class);
});

test('invalid explicit CLI selection never falls back to an available runtime', function (mixed $candidate) {
    config(['assistant.provider.dns_php_cli' => $candidate]);
    expect(fn () => resolveAssistantCli('cli', PHP_BINARY, PHP_BINDIR))->toThrow(HttpException::class);
})->with(['relative' => 'php', 'missing' => '/unavailable/php', 'directory' => '/tmp', 'not CLI' => '/usr/bin/false', 'empty' => '', 'wrong type' => 123]);

test('CLI probe obeys the shared DNS deadline before spawning', function () {
    config(['assistant.provider.dns_php_cli' => PHP_BINARY]);
    expect(fn () => resolveAssistantCli('fpm-fcgi', '/unavailable/php-fpm', '/unavailable', hrtime(true) / 1e9 - 1))->toThrow(HttpException::class);
});
