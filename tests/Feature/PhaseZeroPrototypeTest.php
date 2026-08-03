<?php

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('serves every phase zero prototype journey', function (string $routeName, string $component) {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);
    Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'dashboard' => ['dashboard', 'Dashboard'],
    'guided onboarding' => ['onboarding', 'Onboarding'],
    'invoice composer' => ['invoices.create', 'Invoices/Create'],
    'AGT homologation connection' => ['agt.connection.show', 'Agt/Connection/Show'],
    'data import workflow' => ['imports.index', 'Imports/Index'],
]);

test('uses the Angola fiscal defaults', function () {
    expect(config('app.timezone'))->toBe('Africa/Luanda')
        ->and(config('app.locale'))->toBe('pt_AO')
        ->and(config('app.fallback_locale'))->toBe('pt');
});

test('ships the complete phase zero governance baseline', function () {
    $requiredArtifacts = [
        'README.md',
        'source-register.md',
        'compliance-matrix.md',
        'agt-clarification-request.md',
        'certification-plan.md',
        'prototype-coverage.md',
        'adr/0001-tenant-and-legal-entity-boundaries.md',
        'adr/0002-immutable-fiscal-ledger.md',
        'adr/0003-versioned-agt-gateway.md',
        'adr/0004-signing-key-custody.md',
        'adr/0005-contingency-and-offline-issuing.md',
        'adr/0006-saft-and-accounting-scope.md',
        'adr/0007-platform-and-recovery.md',
        'adr/0008-interface-system.md',
    ];

    foreach ($requiredArtifacts as $artifact) {
        expect(base_path("docs/phase-0/{$artifact}"))->toBeFile();
    }

    $matrix = file_get_contents(base_path('docs/phase-0/compliance-matrix.md'));

    expect($matrix)
        ->not->toBeFalse()
        ->and(substr_count($matrix, '| AGT-') + substr_count($matrix, '| SEC-') + substr_count($matrix, '| OPS-') + substr_count($matrix, '| SAFT-') + substr_count($matrix, '| UX-'))
        ->toBeGreaterThanOrEqual(40);
});

test('does not place private key material in the phase zero dossier', function () {
    $sourceRegister = file_get_contents(base_path('docs/phase-0/source-register.md'));

    expect($sourceRegister)
        ->not->toBeFalse()
        ->not->toContain('-----BEGIN PRIVATE KEY-----')
        ->not->toContain('-----BEGIN RSA PRIVATE KEY-----');
});
