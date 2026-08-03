<?php

use App\LegalEntityStatus;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\TaxRegime;
use App\WorkspaceRole;
use Spatie\Activitylog\Models\Activity;

function validCompanyProfile(array $overrides = []): array
{
    return [
        'legal_name' => 'Kwanza Comércio, Lda.',
        'trade_name' => 'Kwanza Comércio',
        'tax_identification_number' => '541.234.567-8',
        'tax_regime' => TaxRegime::General->value,
        'main_cae_code' => '46 900',
        'establishment_code' => 'sede-01',
        'establishment_name' => 'Sede',
        'address_line' => 'Rua Rei Katyavala, número 20',
        'municipality' => 'Luanda',
        'province_code' => 'lu',
        ...$overrides,
    ];
}

test('an owner persists a normalized company and head office atomically', function () {
    $user = User::factory()->withWorkspace('Kwanza Workspace')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();

    $this->actingAs($user)
        ->put(route('onboarding.update'), validCompanyProfile())
        ->assertRedirect(route('onboarding'))
        ->assertSessionHas('success');

    $legalEntity = $workspace->legalEntities()->firstOrFail();
    $establishment = $legalEntity->establishments()->firstOrFail();

    expect($legalEntity->tax_identification_number)->toBe('5412345678')
        ->and($legalEntity->main_cae_code)->toBe('46900')
        ->and($legalEntity->status)->toBe(LegalEntityStatus::Configured)
        ->and($legalEntity->onboarding_completed_at)->not->toBeNull()
        ->and($establishment->workspace_id)->toBe($workspace->id)
        ->and($establishment->code)->toBe('SEDE-01')
        ->and($establishment->province_code)->toBe('LU')
        ->and($establishment->is_head_office)->toBeTrue();

    expect(Activity::query()
        ->where('log_name', 'legal-entity')
        ->where('subject_id', $legalEntity->id)
        ->where('properties->workspace_id', $workspace->id)
        ->exists())->toBeTrue();
});

test('a viewer can inspect but cannot alter the company profile', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $viewer = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $viewer->id,
        'role' => WorkspaceRole::Viewer,
    ]);

    $this->actingAs($viewer)->get(route('onboarding'))->assertOk();
    $this->actingAs($viewer)
        ->put(route('onboarding.update'), validCompanyProfile())
        ->assertForbidden();

    expect($workspace->legalEntities()->doesntExist())->toBeTrue();
});

test('a legal profile becomes immutable when homologation begins', function () {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => LegalEntityStatus::Homologation,
    ]);

    $this->actingAs($user)
        ->put(route('onboarding.update'), validCompanyProfile([
            'tax_identification_number' => $legalEntity->tax_identification_number,
        ]))
        ->assertForbidden();

    expect($legalEntity->refresh()->legal_name)->not->toBe('Kwanza Comércio, Lda.');
});

test('the same NIF may represent a company in separate isolated workspaces', function () {
    $firstUser = User::factory()->withWorkspace('Primeiro')->create();
    $secondUser = User::factory()->withWorkspace('Segundo')->create();

    $this->actingAs($firstUser)
        ->put(route('onboarding.update'), validCompanyProfile())
        ->assertRedirect(route('onboarding'));

    $this->actingAs($secondUser)
        ->put(route('onboarding.update'), validCompanyProfile())
        ->assertRedirect(route('onboarding'));

    expect(LegalEntity::query()
        ->where('tax_identification_number', '5412345678')
        ->count())->toBe(2);
});
