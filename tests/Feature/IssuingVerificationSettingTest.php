<?php

use App\Fiscal\IssuingVerification;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Spatie\Activitylog\Models\Activity;

test('every company asks for two-step verification to issue until its owner says otherwise', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    expect(IssuingVerification::requiredFor($workspace))->toBeTrue()
        ->and(IssuingVerification::satisfiedBy($owner, $workspace))->toBeFalse();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertInertia(fn ($page) => $page
            ->where('issuingVerification.required', true)
            ->where('issuingVerification.can_change', true));
});

test('the owner can waive it behind a fresh password, and the change is logged', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.issuing-verification.update'), ['requires_mfa_to_issue' => false])
        ->assertRedirect(route('password.confirm'));

    expect($workspace->fresh()->requires_mfa_to_issue)->toBeTrue();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('settings.issuing-verification.update'), ['requires_mfa_to_issue' => false])
        ->assertSessionHas('success');

    expect($workspace->fresh()->requires_mfa_to_issue)->toBeFalse()
        ->and(IssuingVerification::satisfiedBy($owner, $workspace->id))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'security')->where('event', 'issuing-mfa-waived')
            ->where('causer_id', $owner->id)->exists())->toBeTrue();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('settings.issuing-verification.update'), ['requires_mfa_to_issue' => true])
        ->assertSessionHas('success');

    expect($workspace->fresh()->requires_mfa_to_issue)->toBeTrue()
        ->and(Activity::query()->where('event', 'issuing-mfa-required')->exists())->toBeTrue();
});

test('nobody but the owner can change it', function (WorkspaceRole $role) {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => $role,
    ]);

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('settings.issuing-verification.update'), ['requires_mfa_to_issue' => false])
        ->assertForbidden();

    expect($workspace->fresh()->requires_mfa_to_issue)->toBeTrue();

    $this->get(route('settings.security'))
        ->assertInertia(fn ($page) => $page->where('issuingVerification.can_change', false));
})->with([
    'administrator' => WorkspaceRole::Administrator,
    'accountant' => WorkspaceRole::Accountant,
    'billing' => WorkspaceRole::Billing,
]);
