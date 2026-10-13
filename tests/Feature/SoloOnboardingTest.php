<?php

use App\Actions\SaveCompanyProfile;
use App\AgtConnectionStatus;
use App\Fiscal\Agt\CompanySetupProgress;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Data\ResolvedSigningKey;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Spatie\Activitylog\Models\Activity;

/** @return array{user: User, entity: LegalEntity, establishment: Establishment, profile: array<string, string>} */
function soloSetupFixture(): array
{
    Http::preventStrayRequests();
    Http::fake();
    $user = User::factory()->withWorkspace()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('synthetic-only'),
        'two_factor_confirmed_at' => now(),
    ]);
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $profile = [
        'workspace_public_id' => $entity->workspace->public_id,
        'legal_entity_public_id' => $entity->public_id,
        'tax_identification_number' => $entity->tax_identification_number,
        'environment' => 'homologation',
        'basic_auth_username' => 'synthetic-producer-user',
        'basic_auth_password' => 'synthetic-producer-password',
        'product_id' => 'Synthetic Facturac',
        'product_version' => 'test-only',
        'software_validation_number' => 'TEST-NOT-CERTIFIED',
        'establishment_number' => 'SEDE',
        'software_key_reference' => 'synthetic/software',
        'taxpayer_key_reference' => 'synthetic/taxpayer',
        'software_key_fingerprint' => hash('sha256', 'synthetic/software'),
        'taxpayer_key_fingerprint' => hash('sha256', 'synthetic/taxpayer'),
    ];
    config()->set('agt.prepared_associations', [$profile]);
    test()->mock(JwsSigner::class)->shouldReceive('fingerprint')->andReturnUsing(fn (string $reference): string => hash('sha256', $reference));
    test()->mock(SigningKeyResolver::class)->shouldReceive('resolve')->andReturn(new ResolvedSigningKey('synthetic-not-a-private-key', hash('sha256', 'synthetic'), 2048));

    return compact('user', 'entity', 'establishment', 'profile');
}

function associateSoloFixture(array $fixture, array $overrides = []): TestResponse
{
    return test()->actingAs($fixture['user'])->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('agt.connection.associate'), [
            'association_confirmed' => true,
            'tax_identification_number' => $fixture['entity']->tax_identification_number,
            ...$overrides,
        ]);
}

test('a new business starts without an assumed tax regime or readiness', function () {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user)->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page
        ->where('company.tax_regime', '')->where('setupProgress.next_step', 'company')
        ->where('setupProgress.ready_for_draft', false)->where('setupProgress.production_ready', false));
});

test('the server rejects an unconfirmed regime without creating a business', function () {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user)->put(route('onboarding.update'), [
        'legal_name' => 'Empresa Sintética', 'tax_identification_number' => '5000000099',
        'tax_regime' => 'general', 'main_cae_code' => '46900', 'establishment_code' => 'SEDE',
        'establishment_name' => 'Sede', 'address_line' => 'Morada de teste', 'municipality' => 'Luanda', 'province_code' => 'LUA',
    ])->assertSessionHasErrors('tax_regime_confirmed');
    expect($user->currentWorkspace->legalEntities()->count())->toBe(0);
});

test('prepared access is scoped encrypted and repeatable without losing verification', function () {
    $fixture = soloSetupFixture();
    associateSoloFixture($fixture)->assertSessionHasNoErrors()->assertRedirect(route('agt.connection.show'));
    $connection = $fixture['entity']->agtConnections()->firstOrFail();
    expect($connection->getRawOriginal('basic_auth_password'))->not->toContain($fixture['profile']['basic_auth_password']);
    $connection->update(['status' => AgtConnectionStatus::Verified, 'verified_at' => now()]);
    $verifiedAt = $connection->fresh()->verified_at->toIso8601String();
    associateSoloFixture($fixture)->assertSessionHasNoErrors();
    expect($connection->fresh()->status)->toBe(AgtConnectionStatus::Verified)
        ->and($connection->fresh()->verified_at->toIso8601String())->toBe($verifiedAt)
        ->and($fixture['entity']->agtConnections()->count())->toBe(1)
        ->and(Activity::where('event', 'taxpayer-access-associated')->count())->toBe(1);
    Http::assertNothingSent();
    $response = $this->get(route('agt.connection.show'))->assertInertia(fn (Assert $page) => $page
        ->where('associationAvailable', true)->missing('connection.basic_auth_password')
        ->where('setupProgress.next_step', 'series')->where('setupProgress.production_ready', false));
    expect($response->getContent())->not->toContain('synthetic-producer-password')->not->toContain('synthetic-producer-user');
    expect(Activity::all()->toJson())->not->toContain('synthetic-producer-password');
});

test('association refuses untrusted browser choices and requires consent', function (array $overrides, string $error) {
    $fixture = soloSetupFixture();
    associateSoloFixture($fixture, $overrides)->assertSessionHasErrors($error);
    expect(AgtConnection::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    [['association_confirmed' => false], 'association_confirmed'],
    [['tax_identification_number' => '5000000000'], 'tax_identification_number'],
    [['environment' => 'production'], 'environment'],
    [['taxpayer_key_reference' => 'another/company'], 'taxpayer_key_reference'],
    [['software_key_reference' => 'another/software'], 'software_key_reference'],
    [['basic_auth_password' => 'untrusted-secret'], 'basic_auth_password'],
]);

test('a prepared binding must match every part of the business context', function (string $field) {
    $fixture = soloSetupFixture();
    config()->set('agt.prepared_associations', [[...$fixture['profile'], $field => 'wrong-context']]);
    associateSoloFixture($fixture)->assertSessionHasErrors('association_confirmed');
    expect(AgtConnection::count())->toBe(0);
})->with(['workspace_public_id', 'legal_entity_public_id', 'tax_identification_number', 'environment']);

test('missing ambiguous or malformed preparations fail safely', function (mixed $entries) {
    $fixture = soloSetupFixture();
    config()->set('agt.prepared_associations', $entries === 'duplicate' ? [$fixture['profile'], $fixture['profile']] : $entries);
    associateSoloFixture($fixture)->assertSessionHasErrors('association_confirmed');
    expect(AgtConnection::count())->toBe(0);
})->with([[[]], [null], ['duplicate'], [[['bad' => 'entry']]]]);

test('a changed or unavailable prepared key cannot associate access', function (bool $unavailable) {
    $fixture = soloSetupFixture();
    if ($unavailable) {
        $this->mock(JwsSigner::class)->shouldReceive('fingerprint')->andThrow(new SigningKeyUnavailable('secret-path-and-material'));
    } else {
        config()->set('agt.prepared_associations', [[...$fixture['profile'], 'taxpayer_key_fingerprint' => str_repeat('0', 64)]]);
    }
    associateSoloFixture($fixture)->assertSessionHasErrors('association_confirmed');
    expect(AgtConnection::count())->toBe(0)->and(json_encode(session()->all()))->not->toContain('secret-path-and-material');
})->with([true, false]);

test('association never replaces an existing different connection', function () {
    $fixture = soloSetupFixture();
    $existing = AgtConnection::factory()->create(['workspace_id' => $fixture['entity']->workspace_id, 'legal_entity_id' => $fixture['entity']->id]);
    $before = $existing->fresh()->getRawOriginal();
    associateSoloFixture($fixture)->assertSessionHasErrors('association_confirmed');
    expect($existing->fresh()->getRawOriginal())->toBe($before);
});

test('association requires MFA recent confirmation and an administrator', function () {
    $fixture = soloSetupFixture();
    $this->actingAs($fixture['user'])->post(route('agt.connection.associate'))->assertRedirect(route('password.confirm'));
    $fixture['user']->forceFill(['two_factor_confirmed_at' => null])->save();
    associateSoloFixture($fixture)->assertRedirect(route('settings.security'));
    $fixture['user']->forceFill(['two_factor_confirmed_at' => now()])->save();
    WorkspaceMembership::where('user_id', $fixture['user']->id)->update(['role' => WorkspaceRole::Viewer]);
    associateSoloFixture($fixture)->assertForbidden();
    expect(AgtConnection::count())->toBe(0);
});

test('first invoice readiness requires a usable FT series in the same test connection', function (array $overrides, bool $ready) {
    $fixture = soloSetupFixture();
    associateSoloFixture($fixture)->assertSessionHasNoErrors();
    $connection = $fixture['entity']->agtConnections()->firstOrFail();
    $connection->update(['status' => AgtConnectionStatus::Verified, 'verified_at' => now()]);
    FiscalSeries::factory()->create([
        'workspace_id' => $fixture['entity']->workspace_id, 'legal_entity_id' => $fixture['entity']->id,
        'establishment_id' => $fixture['establishment']->id, 'agt_connection_id' => $connection->id,
        'environment' => 'homologation', ...$overrides,
    ]);
    $progress = app(CompanySetupProgress::class)->evaluate($fixture['entity'], $fixture['establishment'], $fixture['user']);
    expect($progress['ready_for_draft'])->toBe($ready)->and($progress['production_ready'])->toBeFalse();
    Http::assertNothingSent();
})->with([
    [[], true], [['document_type' => 'FR'], false], [['status' => 'F'], false],
    [['series_year' => 2020], false], [['next_number' => 1000000], false],
    [['next_number' => 0], false], [['invoicing_method' => 'FEMN'], false],
    [['last_document_date' => '2099-01-01'], false],
]);

test('a production connection or a failed verification cannot make tests ready', function () {
    $fixture = soloSetupFixture();
    AgtConnection::factory()->create(['workspace_id' => $fixture['entity']->workspace_id, 'legal_entity_id' => $fixture['entity']->id, 'environment' => 'production', 'status' => AgtConnectionStatus::Verified, 'verified_at' => now()]);
    $this->actingAs($fixture['user'])->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page->where('setupProgress.next_step', 'association')->where('setupProgress.ready_for_draft', false));
    associateSoloFixture($fixture)->assertSessionHasNoErrors();
    $fixture['entity']->agtConnections()->where('environment', 'homologation')->update(['status' => AgtConnectionStatus::Failed, 'verified_at' => null]);
    $this->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page->where('setupProgress.next_step', 'verification')->where('setupProgress.ready_for_draft', false));
});

test('guided pages expose the next step for each synthetic setup state', function () {
    $fixture = soloSetupFixture();
    $capture = function (string $name, TestResponse $response): void {
        $response->assertOk();
        if (getenv('SOLO_UI_FIXTURES') === '1') {
            file_put_contents(sys_get_temp_dir().'/facturac-solo-'.$name.'.json', json_encode($response->viewData('page'), JSON_THROW_ON_ERROR));
        }
    };
    $capture('company', $this->actingAs($fixture['user'])->get(route('onboarding')));
    $capture('prepared', $this->get(route('agt.connection.show'))->assertInertia(fn (Assert $page) => $page->where('associationAvailable', true)->where('setupProgress.next_step', 'association')));
    config()->set('agt.prepared_associations', []);
    $capture('blocked', $this->get(route('agt.connection.show'))->assertInertia(fn (Assert $page) => $page->where('associationAvailable', false)));
    config()->set('agt.prepared_associations', [$fixture['profile']]);
    associateSoloFixture($fixture)->assertSessionHasNoErrors();
    $connection = $fixture['entity']->agtConnections()->firstOrFail();
    $connection->update(['status' => AgtConnectionStatus::Verified, 'verified_at' => now()]);
    FiscalSeries::factory()->create(['workspace_id' => $fixture['entity']->workspace_id, 'legal_entity_id' => $fixture['entity']->id, 'establishment_id' => $fixture['establishment']->id, 'agt_connection_id' => $connection->id]);
    $capture('ready', $this->get(route('agt.connection.show'))->assertInertia(fn (Assert $page) => $page->where('setupProgress.ready_for_draft', true)));
    $fixture['establishment']->update(['is_active' => false]);
    expect(app(CompanySetupProgress::class)->evaluate($fixture['entity'], $fixture['establishment']->fresh(), $fixture['user'])['ready_for_draft'])->toBeFalse();
    Http::assertNothingSent();
});

test('the customer cannot bypass guided association through the legacy technical form', function () {
    $fixture = soloSetupFixture();
    $this->actingAs($fixture['user'])->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('agt.connection.update'), $fixture['profile'])->assertForbidden();
    expect(AgtConnection::count())->toBe(0);
    $this->get(route('agt.connection.show'))->assertInertia(fn (Assert $page) => $page->where('permissions.manual_setup', false));
});

test('associated taxpayer identity cannot drift before verification while bank details remain editable', function () {
    $fixture = soloSetupFixture();
    associateSoloFixture($fixture)->assertSessionHasNoErrors();
    expect($fixture['user']->can('update', $fixture['entity']))->toBeFalse()
        ->and($fixture['user']->can('updateBankDetails', $fixture['entity']))->toBeTrue();
    $this->actingAs($fixture['user'])->put(route('onboarding.update'), ['tax_identification_number' => '5000000000'])->assertForbidden();
    expect($fixture['entity']->fresh()->tax_identification_number)->toBe($fixture['profile']['tax_identification_number']);
    expect(fn () => app(SaveCompanyProfile::class)->execute($fixture['entity']->workspace, []))
        ->toThrow(LogicException::class, 'locked after AGT access is associated');
});
