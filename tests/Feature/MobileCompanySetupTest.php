<?php

use App\Actions\SignInMobileDevice;
use App\AgtConnectionStatus;
use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Data\ResolvedSigningKey;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a phone can see the company step before a fiscal profile exists', function () {
    $user = User::factory()->withWorkspace()->create();
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    $this->withToken($token)->getJson(route('mobile.v1.company.setup'))->assertOk()
        ->assertJsonPath('data.next_step', 'company')
        ->assertJsonPath('data.workspace_public_id', $user->currentWorkspace->public_id)
        ->assertJsonPath('data.continue_url', route('company-setup.show', $user->currentWorkspace))
        ->assertJsonPath('data.environment', 'homologation')
        ->assertJsonPath('data.production_ready', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($user->currentWorkspace->legalEntities()->count())->toBe(0);
});

test('setup rejects a stale company pin and requires a mobile session', function () {
    $user = User::factory()->withWorkspace()->create();
    $this->getJson(route('mobile.v1.company.setup'))->assertUnauthorized();
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    $this->withToken($token)->withHeader('X-Company', 'other-company')
        ->getJson(route('mobile.v1.company.setup'))->assertConflict()
        ->assertJsonPath('error.code', 'COMPANY_CHANGED')->assertJsonMissingPath('data');
});

test('mobile and web share each setup step and the handoff resolves its current destination', function (string $step) {
    $user = User::factory()->withWorkspace()->create([
        'two_factor_secret' => $step === 'security' ? null : Fortify::currentEncrypter()->encrypt('synthetic-only'),
        'two_factor_confirmed_at' => $step === 'security' ? null : now(),
    ]);
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $this->mock(SigningKeyResolver::class)->shouldReceive('resolve')->andReturn(new ResolvedSigningKey('synthetic', 'synthetic', 2048));
    if (in_array($step, ['verification', 'series', 'draft'])) {
        $connection = AgtConnection::factory()->create([
            'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
            'status' => $step === 'verification' ? AgtConnectionStatus::Ready : AgtConnectionStatus::Verified,
            'verified_at' => $step === 'verification' ? null : now(),
        ]);
        if ($step === 'draft') {
            FiscalSeries::factory()->create([
                'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
                'establishment_id' => $establishment->id, 'agt_connection_id' => $connection->id,
            ]);
        }
    }
    $web = $this->actingAs($user)->get(route('onboarding'))->assertOk()->viewData('page')['props']['setupProgress'];
    Sanctum::actingAs($user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->getJson(route('mobile.v1.company.setup'))->assertOk()
        ->assertJsonPath('data.next_step', $step)
        ->assertJsonPath('data.items', $web['items'])
        ->assertJsonPath('data.ready_for_draft', $step === 'draft')
        ->assertJsonPath('data.production_ready', false);
    $target = match ($step) {
        'security' => route('settings.security'),
        'association' => route('agt.connection.show').'#taxpayer-association',
        'verification' => route('agt.connection.show').'#connection-check',
        'series' => route('agt.connection.show').'#authorized-series',
        'draft' => route('invoices.create'),
    };
    $this->get(route('company-setup.show', $user->currentWorkspace))->assertRedirect($target);
})->with(['security', 'association', 'verification', 'series', 'draft']);

test('setup explains when an administrator must continue', function () {
    $user = User::factory()->withWorkspace()->create();
    WorkspaceMembership::where('user_id', $user->id)->update(['role' => WorkspaceRole::Viewer]);
    Sanctum::actingAs($user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->getJson(route('mobile.v1.company.setup'))->assertOk()
        ->assertJsonPath('data.continue_url', null)
        ->assertJsonPath('data.help', 'Peça ao administrador da empresa para concluir este passo.');
});

test('a browser must explicitly switch company and cannot use a revoked handoff', function () {
    $user = User::factory()->withWorkspace('First')->create();
    $original = $user->currentWorkspace;
    $other = User::factory()->withWorkspace('Second')->create()->currentWorkspace;
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner]);
    $this->actingAs($user)->get(route('company-setup.show', $other))->assertInertia(fn (Assert $page) => $page
        ->component('CompanySetupHandoff')->where('company.public_id', $other->public_id));
    expect($user->fresh()->current_workspace_id)->toBe($original->id);
    $this->put(route('company-setup.update', $other))->assertRedirect(route('company-setup.show', $other));
    expect($user->fresh()->current_workspace_id)->toBe($other->id);
    $this->get(route('company-setup.show', $other))->assertRedirect(route('onboarding').'#company-profile');
    WorkspaceMembership::where('workspace_id', $original->id)->where('user_id', $user->id)->update(['is_active' => false]);
    $this->get(route('company-setup.show', $original))->assertForbidden();
    $this->put(route('company-setup.update', $original))->assertForbidden();
    expect($user->fresh()->current_workspace_id)->toBe($other->id);
});

test('a handoff requires browser authentication and rejects another company', function () {
    $user = User::factory()->withWorkspace()->create();
    $foreign = User::factory()->withWorkspace()->create()->currentWorkspace;
    $this->get(route('company-setup.show', $foreign))->assertRedirect(route('login'));
    $this->actingAs($user)->get(route('company-setup.show', $foreign))->assertForbidden();
    $this->put(route('company-setup.update', $foreign))->assertForbidden();
});

test('a previously opened handoff advances after setup changes and ignores supplied destinations', function () {
    $user = User::factory()->withWorkspace()->create();
    $url = route('company-setup.show', $user->currentWorkspace).'?redirect=https://unrelated.example';
    $this->actingAs($user)->get($url)->assertRedirect(route('onboarding').'#company-profile');
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $this->get($url)->assertRedirect(route('settings.security'));
});

test('a token without mobile ability cannot read setup', function () {
    $user = User::factory()->withWorkspace()->create();
    $token = $user->createToken('Other integration', ['unrelated'])->plainTextToken;
    $this->withToken($token)->getJson(route('mobile.v1.company.setup'))->assertForbidden();
});
