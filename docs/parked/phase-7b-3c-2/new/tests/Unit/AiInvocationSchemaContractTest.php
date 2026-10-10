<?php

use App\Fiscal\TenantAiInvocationSchema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('invocation successor listings reproduce the approved reviewed bytes', function () {
    $evidence = json_decode(file_get_contents(base_path('docs/phase-7b-3c-2-migration-review-evidence.json')), true, flags: JSON_THROW_ON_ERROR);
    $approved = array_column($evidence['sql_listings'], 'sha256', 'id');
    foreach (['I1_up' => 'install', 'I2_up' => 'validation', 'I1_down' => 'down'] as $listing => $method) {
        expect(hash('sha256', TenantAiInvocationSchema::$method()))->toBe($approved[$listing]);
    }
    expect($approved)->toBe($evidence['independent_review_of_revision_2']['listing_sha256_reviewed'])
        ->and(hash_file('sha256', base_path('docs/phase-7b-3c-2-migration-review-package.md')))->toBe($evidence['report_sha256']);
});

test('SQLite skips the invocation successor and keeps the closed historical boundary', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite fast-boundary assertion.');
    }
    $migrations = glob(database_path('migrations/2026_10_10_010*_ai_verification_invocation_successor.php'));
    expect($migrations)->toHaveCount(2);
    foreach ($migrations as $path) {
        expect((require $path)->shouldRun())->toBeFalse();
    }
});
