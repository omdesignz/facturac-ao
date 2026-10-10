<?php

use App\Fiscal\TenantAiVerificationSchema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('all executable schema listings reproduce the approved frozen bytes', function () {
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-1-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach (['metadata', 'constraints', 'guards', 'validation', 'reconciliation', 'downPreflight', 'integrityDown', 'metadataDown'] as $index => $method) {
        expect(TenantAiVerificationSchema::$method())->toBe($evidence['sql_listings'][$index]['sql'])
            ->and(hash('sha256', TenantAiVerificationSchema::$method()))->toBe($evidence['sql_listings'][$index]['sha256']);
    }
    expect(hash_file('sha256', base_path('docs/phase-7b-3c-1-migration-review-package.md')))->toBe($evidence['report_sha256']);
});

test('SQLite preserves the historical closed boundary instead of emulating deferred PostgreSQL authority', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite fast-boundary assertion.');
    }
    foreach (glob(database_path('migrations/2026_10_09_020*verification*.php')) as $path) {
        $migration = require $path;
        expect($migration->shouldRun())->toBeFalse();
    }
    expect(fn () => TenantAiVerificationSchema::reconcile([], fn () => null))
        ->toThrow(RuntimeException::class, 'AI reconciliation requires its own PostgreSQL transaction.');
});
