<?php

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

test('a member can switch only to an active workspace they belong to', function () {
    $user = User::factory()->withWorkspace('Primeiro espaço')->create();
    $firstWorkspace = $user->currentWorkspace()->firstOrFail();
    $secondWorkspace = Workspace::factory()->create(['created_by_user_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $secondWorkspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Administrator,
    ]);
    $foreignWorkspace = Workspace::factory()->create();

    $this->actingAs($user)
        ->put(route('workspace.current.update', $secondWorkspace))
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->current_workspace_id)->toBe($secondWorkspace->id)
        ->and(Activity::query()->where('event', 'workspace-switched')->exists())->toBeTrue();

    $this->actingAs($user)
        ->put(route('workspace.current.update', $foreignWorkspace))
        ->assertForbidden();

    expect($user->refresh()->current_workspace_id)->toBe($secondWorkspace->id)
        ->and($firstWorkspace->id)->not->toBe($secondWorkspace->id);
});

test('an inactive current membership falls back safely to an active membership', function () {
    $user = User::factory()->withWorkspace('Espaço inactivo')->create();
    $inactiveWorkspace = $user->currentWorkspace()->firstOrFail();
    $user->workspaceMemberships()
        ->where('workspace_id', $inactiveWorkspace->id)
        ->update(['is_active' => false]);

    $activeWorkspace = Workspace::factory()->create(['created_by_user_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $activeWorkspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->refresh()->current_workspace_id)->toBe($activeWorkspace->id);
});

test('the database rejects establishments attached across tenant boundaries', function () {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();
    $legalEntity = LegalEntity::factory()->create([
        'workspace_id' => $firstWorkspace->id,
    ]);

    expect(fn () => Establishment::query()->create([
        'workspace_id' => $secondWorkspace->id,
        'legal_entity_id' => $legalEntity->id,
        'code' => 'SEDE',
        'name' => 'Sede inválida',
        'address_line' => 'Rua sem acesso, Luanda',
        'municipality' => 'Luanda',
        'province_code' => 'LU',
        'timezone' => 'Africa/Luanda',
        'is_head_office' => true,
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

test('resource policies reject a different current workspace even when membership exists', function () {
    $user = User::factory()->withWorkspace('Espaço actual')->create();
    $otherWorkspace = Workspace::factory()->create(['created_by_user_id' => $user->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $user->id,
    ]);
    $otherEntity = LegalEntity::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);
    $otherEstablishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $otherWorkspace->id,
        'legal_entity_id' => $otherEntity->id,
    ]);

    expect($user->can('view', $otherEntity))->toBeFalse()
        ->and($user->can('update', $otherEntity))->toBeFalse()
        ->and($user->can('view', $otherEstablishment))->toBeFalse()
        ->and($user->can('update', $otherEstablishment))->toBeFalse();
});
