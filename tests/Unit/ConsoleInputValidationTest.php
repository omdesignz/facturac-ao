<?php

use Tests\TestCase;

uses(TestCase::class);

test('commands reject non-text identifiers and date options before doing work', function (string $command, array $parameters) {
    $this->artisan($command, $parameters)->expectsOutputToContain('texto')->assertFailed();
})->with([
    'owner email' => ['assistant:workspace', ['owner' => ['owner@example.test']]],
    'workspace approval' => ['assistant:workspace', ['owner' => 'owner@example.test', 'workspace' => ['workspace-id']]],
    'workspace readiness' => ['assistant:check', ['workspace' => ['workspace-id']]],
    'support email' => ['support:staff', ['email' => ['support@example.test']]],
    'recurring date' => ['recurring:remind-due', ['--on' => ['2026-11-17']]],
    'notification date' => ['notifications:scan', ['--on' => ['2026-11-17']]],
    'daily close date' => ['notifications:daily-close', ['--on' => ['2026-11-17']]],
]);
