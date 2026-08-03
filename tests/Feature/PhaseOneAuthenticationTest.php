<?php

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to login from every protected application journey', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'dashboard' => ['dashboard'],
    'onboarding' => ['onboarding'],
    'invoice prototype' => ['invoices.create'],
    'AGT prototype' => ['agt.submissions.index'],
    'import prototype' => ['imports.index'],
]);

test('registration creates an isolated owner workspace atomically', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Maria José',
        'email' => 'MARIA@EXAMPLE.COM',
        'workspace_name' => 'Comércio Horizonte',
        'password' => 'StrongPassword!2026',
        'password_confirmation' => 'StrongPassword!2026',
    ]);

    $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
    $workspace = Workspace::query()->where('name', 'Comércio Horizonte')->firstOrFail();
    $membership = WorkspaceMembership::query()
        ->whereBelongsTo($user)
        ->whereBelongsTo($workspace)
        ->firstOrFail();

    $response->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);

    expect($user->current_workspace_id)->toBe($workspace->id)
        ->and($workspace->created_by_user_id)->toBe($user->id)
        ->and($membership->role)->toBe(WorkspaceRole::Owner)
        ->and($membership->is_active)->toBeTrue();
});

test('registration normalizes email before enforcing uniqueness', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'Outra pessoa',
            'email' => 'OWNER@EXAMPLE.COM',
            'workspace_name' => 'Não deve existir',
            'password' => 'StrongPassword!2026',
            'password_confirmation' => 'StrongPassword!2026',
        ])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'owner@example.com')->count())->toBe(1)
        ->and(Workspace::query()->where('name', 'Não deve existir')->doesntExist())->toBeTrue();
});

test('unverified users cannot enter a workspace application', function () {
    $user = User::factory()->unverified()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('verified users without a workspace are guided through workspace setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('workspace.setup'));

    $this->actingAs($user)
        ->get(route('workspace.setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('WorkspaceSetup'));
});

test('a workspace can be created only once through the setup workflow', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspace.store'), ['workspace_name' => 'Ngunza Serviços'])
        ->assertRedirect(route('onboarding'));

    $workspaceId = $user->refresh()->current_workspace_id;

    $this->actingAs($user)
        ->post(route('workspace.store'), ['workspace_name' => 'Duplicado'])
        ->assertRedirect(route('dashboard'));

    expect($workspaceId)->not->toBeNull()
        ->and($user->workspaceMemberships()->count())->toBe(1)
        ->and(Workspace::query()->where('name', 'Duplicado')->doesntExist())->toBeTrue();
});

test('shared page data exposes only the safe authenticated identity and a masked NIF', function () {
    $user = User::factory()->withWorkspace('Empresa Segura')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5412345678',
    ]);
    Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password')
            ->missing('auth.user.two_factor_secret')
            ->where('currentWorkspace.legal_entity.masked_nif', '541•••••78')
        );
});
