<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->withoutVite();
    $this->owner = User::factory()->withWorkspace()->create();
    $this->owner->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $this->entity = LegalEntity::factory()->configured()->create(['workspace_id' => $this->owner->current_workspace_id]);
    $this->actingAs($this->owner)->withSession([
        config('work_session.started_at_key') => time(),
        'auth.password_confirmed_at' => time(),
    ]);
    $this->payload = ['name' => 'ERP', 'entity_public_id' => $this->entity->public_id, 'environment' => 'production', 'scopes' => ['documents:read', 'customers:read']];
});

test('owners create a scoped key once and the settings page never returns secrets', function () {
    $response = $this->postJson(route('integrations.settings.store'), $this->payload)->assertCreated();
    $secret = $response->json('token');
    $credential = IntegrationCredential::firstOrFail();
    expect($credential->secret_hash)->toBe(hash('sha256', $secret));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(json_encode(Activity::all()))->not->toContain($secret);
    $this->get(route('integrations.settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Integrations')->has('integrations', 1)
        ->where('integrations.0.name', 'ERP')
        ->where('integrations.0.entity_public_id', $this->entity->public_id)
        ->missing('integrations.0.credentials.0.secret_hash')->missing('integrations.0.credentials.0.token'));
    expect(json_encode(session()->all()))->not->toContain($secret);
});

test('integration management rejects foreign entities and other tenants credentials', function () {
    $otherEntity = LegalEntity::factory()->configured()->create();
    $this->postJson(route('integrations.settings.store'), [...$this->payload, 'entity_public_id' => $otherEntity->public_id])->assertNotFound();
    $foreign = Integration::factory()->create();
    $credential = IntegrationCredential::factory()->create(['integration_id' => $foreign->id]);
    $this->postJson(route('integrations.settings.rotate', [$foreign->public_id, $credential->public_id]), ['revision' => 1])->assertNotFound();
    $this->deleteJson(route('integrations.settings.destroy', $foreign->public_id), ['revision' => 1])->assertNotFound();
    $this->get(route('integrations.settings.index'))->assertInertia(fn (Assert $page) => $page->has('integrations', 0));
});

test('integration management requires current human authority', function (string $condition) {
    if ($condition === 'role') {
        WorkspaceMembership::where('user_id', $this->owner->id)->update(['role' => 'viewer']);
    } elseif ($condition === 'confirmation') {
        $this->withSession(['auth.password_confirmed_at' => time() - 301]);
    } elseif ($condition === 'mfa') {
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
    } elseif ($condition === 'impersonation') {
        $this->withSession([config('impersonation.session.impersonator') => $this->owner->id]);
    } elseif ($condition === 'http') {
        $this->postJson(str_replace('https:', 'http:', route('integrations.settings.store')), $this->payload)->assertForbidden();
        expect(Integration::count())->toBe(0);

        return;
    }
    $response = $this->postJson(route('integrations.settings.store'), $this->payload);
    expect($response->status())->toBeIn([302, 403, 423]);
    expect(Integration::count())->toBe(0);
})->with(['role', 'confirmation', 'mfa', 'impersonation', 'http']);

test('integration settings validate scopes and restrict master data to production', function () {
    foreach ([['scopes' => ['*']], ['scopes' => []], ['environment' => 'homologation'], ['name' => '']] as $invalid) {
        $this->postJson(route('integrations.settings.store'), [...$this->payload, ...$invalid])->assertUnprocessable();
    }
    expect(Integration::count())->toBe(0);
});

test('rotation preserves grants and stale revisions cannot revoke a changed integration', function () {
    $first = $this->postJson(route('integrations.settings.store'), $this->payload)->assertCreated()->json();
    $second = $this->postJson(route('integrations.settings.rotate', [$first['integration_public_id'], $first['credential_public_id']]), ['revision' => 1, 'scopes' => ['*']])->assertCreated()->json();
    $integration = Integration::firstOrFail();
    expect($integration->revision)->toBe(2);
    $old = IntegrationCredential::where('public_id', $first['credential_public_id'])->firstOrFail();
    expect($old->expires_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue();
    $this->deleteJson(route('integrations.settings.destroy', $integration->public_id), ['revision' => 1])->assertConflict();
    $this->deleteJson(route('integrations.settings.revoke-credential', [$integration->public_id, $first['credential_public_id']]), ['revision' => 2])->assertOk();
    expect($old->fresh()->revoked_at)->not->toBeNull();
    expect(IntegrationCredential::where('public_id', $second['credential_public_id'])->firstOrFail()->revoked_at)->toBeNull();
});

test('expired plans cannot create credentials but can list and revoke existing access without fresh password confirmation', function () {
    $created = $this->postJson(route('integrations.settings.store'), $this->payload)->assertCreated()->json();
    $this->owner->currentWorkspace->forceFill(['created_at' => now()->subDays(30)])->save();
    $this->postJson(route('integrations.settings.store'), $this->payload)->assertForbidden();
    $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
    $this->get(route('integrations.settings.index'))->assertOk();
    $this->deleteJson(route('integrations.settings.destroy', $created['integration_public_id']), ['revision' => 1])->assertOk();
    expect(Integration::firstOrFail()->revoked_at)->not->toBeNull();
});

test('replacement requires no active keys and revocation is permanent', function () {
    $created = $this->postJson(route('integrations.settings.store'), $this->payload)->assertCreated()->json();
    $this->postJson(route('integrations.settings.replace', $created['integration_public_id']), ['revision' => 1])->assertConflict();
    $this->deleteJson(route('integrations.settings.revoke-credential', [$created['integration_public_id'], $created['credential_public_id']]), ['revision' => 1])->assertOk();
    $this->postJson(route('integrations.settings.replace', $created['integration_public_id']), ['revision' => 2])->assertCreated();
    $this->deleteJson(route('integrations.settings.destroy', $created['integration_public_id']), ['revision' => 3])->assertOk();
    $this->postJson(route('integrations.settings.replace', $created['integration_public_id']), ['revision' => 4])->assertConflict();
});

test('published contracts are public allowlisted JSON and do not enable integrations', function () {
    config(['integrations.enabled' => false, 'integrations.commands_enabled' => false]);
    auth()->logout();
    foreach (['read-v1', 'billing-v2', 'qualified-agt-v2', 'customer-create-v1', 'catalogue-service-create-v1', 'document-issue-v1'] as $contract) {
        $response = $this->get(route('integrations.contract', $contract))->assertOk()->assertHeader('Content-Type', 'application/json');
        expect($response->baseResponse->getFile()->getRealPath())->toBe(realpath(base_path('docs/openapi-external-'.$contract.'.json')));
    }
    $this->get(route('integrations.contract', 'unknown'))->assertNotFound();
    expect(config('integrations.enabled'))->toBeFalse();
    expect(IntegrationCredential::count())->toBe(0);
});

test('a key created in settings authenticates only its granted external API and stops after revocation', function () {
    config(['integrations.enabled' => true]);
    $created = $this->postJson(route('integrations.settings.store'), $this->payload)->assertCreated()->json();
    $url = route('integrations.v1.customers.index', [$this->owner->currentWorkspace->public_id, $this->entity->public_id, 'production']);
    $this->withHeader('Authorization', 'Bearer '.$created['token'])->getJson($url)->assertOk();
    $this->deleteJson(route('integrations.settings.destroy', $created['integration_public_id']), ['revision' => 1])->assertOk();
    $this->getJson($url)->assertUnauthorized();
});

test('credential creation rejects a session request without CSRF proof outside the testing bypass', function () {
    $this->app['env'] = 'local';
    try {
        $this->postJson(route('integrations.settings.store'), $this->payload)->assertStatus(419);
        expect(Integration::count())->toBe(0);
    } finally {
        $this->app['env'] = 'testing';
    }
});
