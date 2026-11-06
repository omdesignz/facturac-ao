<?php

use App\Actions\SignInMobileDevice;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../PosFixtures.php';

function companyToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

function secondCompany(User $user, string $name = 'Oficina Kilamba', bool $isActive = true): Workspace
{
    $workspace = Workspace::factory()->create(['name' => $name]);

    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
        'is_active' => $isActive,
    ]);

    return $workspace;
}

it('moves the account to another of its companies, keeps the token, and logs it as the web does', function (): void {
    $company = posCompany();
    $other = secondCompany($company['owner']);
    $token = companyToken($company['owner']);

    $this->withToken($token)->putJson(route('mobile.v1.current-company.update'), ['workspace_public_id' => $other->public_id])
        ->assertOk()
        ->assertJsonPath('data.current_workspace_public_id', $other->public_id)
        ->assertJsonCount(2, 'data.workspaces');

    expect($company['owner']->fresh()->current_workspace_id)->toBe($other->id)
        ->and(Activity::query()->where('event', 'workspace-switched')->where('subject_id', $other->id)->count())->toBe(1);

    $this->withToken($token)->getJson(route('mobile.v1.me'))->assertOk();
});

it('answers a repeat with the same profile and logs nothing more', function (): void {
    $company = posCompany();
    $other = secondCompany($company['owner']);
    $token = companyToken($company['owner']);

    foreach ([1, 2] as $attempt) {
        $this->withToken($token)->putJson(route('mobile.v1.current-company.update'), ['workspace_public_id' => $other->public_id])
            ->assertOk()
            ->assertJsonPath('data.current_workspace_public_id', $other->public_id);
    }

    expect(Activity::query()->where('event', 'workspace-switched')->count())->toBe(1);
});

it('will not move to a company the person is not in, or has been removed from', function (): void {
    $company = posCompany();
    $stranger = Workspace::factory()->create();
    $suspended = secondCompany($company['owner'], 'Antiga', isActive: false);
    $token = companyToken($company['owner']);

    foreach ([$stranger, $suspended] as $workspace) {
        $this->withToken($token)->putJson(route('mobile.v1.current-company.update'), ['workspace_public_id' => $workspace->public_id])
            ->assertNotFound();
    }

    expect($company['owner']->fresh()->current_workspace_id)->toBe($company['legalEntity']->workspace_id);
});

it('shows the new company\'s records after the move, and none of the old ones', function (): void {
    $company = posCompany();
    $other = secondCompany($company['owner']);
    $otherEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $other->id]);
    $mine = Customer::factory()->create(['workspace_id' => $company['legalEntity']->workspace_id, 'legal_entity_id' => $company['legalEntity']->id]);
    $theirs = Customer::factory()->create(['workspace_id' => $other->id, 'legal_entity_id' => $otherEntity->id]);
    $token = companyToken($company['owner']);

    $this->withToken($token)->putJson(route('mobile.v1.current-company.update'), ['workspace_public_id' => $other->public_id])->assertOk();
    auth()->forgetGuards();

    $rows = collect($this->withToken($token)->getJson(route('mobile.v1.customers.index'))->assertOk()->json('data'))->pluck('public_id');

    expect($rows)->toContain($theirs->public_id)->not->toContain($mine->public_id);
});

it('stops a screen built for the old company from reading or writing in the new one', function (): void {
    $company = posCompany();
    $other = secondCompany($company['owner']);
    LegalEntity::factory()->configured()->create(['workspace_id' => $other->id]);
    $old = $company['legalEntity']->workspace->public_id;
    $token = companyToken($company['owner']);
    $company['owner']->forceFill(['current_workspace_id' => $other->id])->save();
    $customersBefore = Customer::query()->count();

    $this->withToken($token)->withHeader('X-Company', $old)->getJson(route('mobile.v1.today'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'COMPANY_CHANGED');

    $this->withToken($token)->withHeader('X-Company', $old)->postJson(route('mobile.v1.customers.store'), [
        'client_key' => (string) Str::uuid(),
        'name' => 'Cliente errado',
        'tax_identification_number' => '5000000999',
    ])->assertConflict();

    expect(Customer::query()->count())->toBe($customersBefore);

    $this->withToken($token)->withHeader('X-Company', $other->public_id)->getJson(route('mobile.v1.today'))->assertOk();
});

it('lets an older app with no company header carry on', function (): void {
    $company = posCompany();

    $this->withToken(companyToken($company['owner']))->getJson(route('mobile.v1.today'))->assertOk();
});
