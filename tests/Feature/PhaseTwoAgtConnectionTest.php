<?php

use App\AgtConnectionCheckStatus;
use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\LegalEntityStatus;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, workspace: Workspace, legal_entity: LegalEntity}
 */
function phaseTwoCompany(bool $withMfa = false): array
{
    $user = User::factory()->withWorkspace('VAP Testes')->create();
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'tax_identification_number' => '5000000001',
    ]);
    Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    if ($withMfa) {
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('phase-two-secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['phase-two-recovery'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return [
        'user' => $user,
        'workspace' => $workspace,
        'legal_entity' => $legalEntity,
    ];
}

/** @return array<string, string|null> */
function validAgtConnectionProfile(array $overrides = []): array
{
    return [
        'basic_auth_username' => 'agt-hml-user',
        'basic_auth_password' => 'agt-hml-password',
        'product_id' => 'facturac.ao',
        'product_version' => '0.3.0',
        'software_validation_number' => 'AGT-SOFTWARE-001',
        'establishment_number' => 'EST-AGT-001',
        'software_key_reference' => 'software/vap-hml',
        'taxpayer_key_reference' => 'taxpayer/5000000001-hml',
        ...$overrides,
    ];
}

function provisionPhaseTwoKey(string $directory, string $reference): void
{
    $path = $directory.'/'.$reference.'.pem';
    File::ensureDirectoryExists(dirname($path));
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($key === false || ! openssl_pkey_export($key, $privateKeyPem)) {
        throw new RuntimeException('Unable to create the synthetic phase two key.');
    }

    File::put($path, $privateKeyPem);
}

/**
 * @return array{directory: string, profile: array<string, string|null>}
 */
function provisionPhaseTwoKeys(): array
{
    $directory = storage_path('framework/testing/agt-phase-two-'.Str::uuid());
    provisionPhaseTwoKey($directory, 'software/vap-hml');
    provisionPhaseTwoKey($directory, 'taxpayer/5000000001-hml');
    config()->set('agt.signing.key_directory', $directory);

    return [
        'directory' => $directory,
        'profile' => validAgtConnectionProfile(),
    ];
}

function phaseTwoPasswordSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('a workspace member can inspect the connection without receiving secrets', function () {
    $company = phaseTwoCompany();
    AgtConnection::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
        'basic_auth_username' => 'private-agt-user',
        'basic_auth_password' => 'private-agt-password',
    ]);

    $this->actingAs($company['user'])
        ->get(route('agt.connection.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Agt/Connection/Show')
            ->where('connection.has_basic_credentials', true)
            ->where('connection.credential_identity', 'pr••••••••••••er')
            ->missing('connection.basic_auth_username')
            ->missing('connection.basic_auth_password')
            ->where('guardrails.production_enabled', false)
            ->where('guardrails.private_keys_in_database', false));
});

test('MFA and recent password confirmation protect every connection mutation', function () {
    $company = phaseTwoCompany();

    $this->actingAs($company['user'])
        ->withSession(phaseTwoPasswordSession())
        ->put(route('agt.connection.update'), validAgtConnectionProfile())
        ->assertRedirect(route('settings.security'));

    expect(AgtConnection::query()->doesntExist())->toBeTrue();

    $this->flushSession();
    $mfaCompany = phaseTwoCompany(withMfa: true);

    $this->actingAs($mfaCompany['user'])
        ->put(route('agt.connection.update'), validAgtConnectionProfile())
        ->assertRedirect(route('password.confirm'));

    expect(AgtConnection::query()
        ->where('legal_entity_id', $mfaCompany['legal_entity']->id)
        ->doesntExist())->toBeTrue();
});

test('a viewer can inspect but cannot configure or test the AGT connection', function () {
    $company = phaseTwoCompany();
    $viewer = User::factory()->create([
        'current_workspace_id' => $company['workspace']->id,
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('viewer-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['viewer-recovery'])),
        'two_factor_confirmed_at' => now(),
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'user_id' => $viewer->id,
        'role' => WorkspaceRole::Viewer,
    ]);

    $this->actingAs($viewer)
        ->get(route('agt.connection.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.manage', false)
            ->where('permissions.test', false));

    $this->actingAs($viewer)
        ->withSession(phaseTwoPasswordSession())
        ->put(route('agt.connection.update'), validAgtConnectionProfile())
        ->assertForbidden();
});

test('credentials are encrypted at rest and never flashed after validation errors', function () {
    $company = phaseTwoCompany(withMfa: true);

    $this->actingAs($company['user'])
        ->withSession(phaseTwoPasswordSession())
        ->put(route('agt.connection.update'), validAgtConnectionProfile([
            'product_id' => '',
        ]))
        ->assertSessionHasErrors('product_id')
        ->assertSessionMissing('_old_input.basic_auth_password');

    $this->actingAs($company['user'])
        ->withSession(phaseTwoPasswordSession())
        ->put(route('agt.connection.update'), validAgtConnectionProfile())
        ->assertRedirect(route('agt.connection.show'))
        ->assertSessionHas('success');

    $connection = AgtConnection::query()->firstOrFail();
    $raw = DB::table('agt_connections')->where('id', $connection->id)->first();

    expect($connection->basic_auth_username)->toBe('agt-hml-user')
        ->and($connection->basic_auth_password)->toBe('agt-hml-password')
        ->and($raw?->basic_auth_username)->not->toContain('agt-hml-user')
        ->and($raw?->basic_auth_password)->not->toContain('agt-hml-password')
        ->and(Activity::query()->where('log_name', 'agt-connection')->get()
            ->pluck('properties')->flatten()->implode(' '))
        ->not->toContain('agt-hml-user')
        ->not->toContain('agt-hml-password');
});

test('a successful list-series probe records evidence and advances the company to homologation', function () {
    $company = phaseTwoCompany(withMfa: true);
    $keys = provisionPhaseTwoKeys();
    Http::preventStrayRequests();
    Http::fake([
        'https://sifphml.minfin.gov.ao/sigt/fe/v1/listarSeries' => Http::response([
            'resultCode' => '0',
            'errorList' => [],
            'seriesResultCount' => '0',
            'seriesInfo' => [],
        ]),
    ]);

    try {
        $this->actingAs($company['user'])
            ->withSession(phaseTwoPasswordSession())
            ->put(route('agt.connection.update'), $keys['profile'])
            ->assertRedirect(route('agt.connection.show'));

        $connection = AgtConnection::query()->firstOrFail();

        expect($connection->status)->toBe(AgtConnectionStatus::Ready)
            ->and($connection->software_key_fingerprint)->toHaveLength(64)
            ->and($connection->taxpayer_key_fingerprint)->toHaveLength(64);

        $this->actingAs($company['user'])
            ->withSession(phaseTwoPasswordSession())
            ->post(route('agt.connection-checks.store'))
            ->assertRedirect(route('agt.connection.show'))
            ->assertSessionHas('success');

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://sifphml.minfin.gov.ao/sigt/fe/v1/listarSeries'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('agt-hml-user:agt-hml-password'))
                && is_array($payload)
                && $payload['schemaVersion'] === '1.2'
                && $payload['taxRegistrationNumber'] === '5000000001'
                && $payload['establishmentNumber'] === 'EST-AGT-001'
                && substr_count((string) $payload['jwsSignature'], '.') === 2
                && substr_count((string) data_get($payload, 'softwareInfo.jwsSoftwareSignature'), '.') === 2;
        });

        $check = AgtConnectionCheck::query()->firstOrFail();

        expect($check->status)->toBe(AgtConnectionCheckStatus::Passed)
            ->and($check->http_status)->toBe(200)
            ->and($check->result_code)->toBe('0')
            ->and($check->request_body_sha256)->toHaveLength(64)
            ->and($check->response_body_sha256)->toHaveLength(64)
            ->and($connection->refresh()->status)->toBe(AgtConnectionStatus::Verified)
            ->and($company['legal_entity']->refresh()->status)->toBe(LegalEntityStatus::Homologation);
    } finally {
        File::deleteDirectory($keys['directory']);
    }
});

test('an AGT authentication failure is recorded without leaking the response or credentials', function () {
    $company = phaseTwoCompany(withMfa: true);
    $keys = provisionPhaseTwoKeys();
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'internalDiagnostic' => 'never expose this body',
        ], 401),
    ]);

    try {
        $this->actingAs($company['user'])
            ->withSession(phaseTwoPasswordSession())
            ->put(route('agt.connection.update'), $keys['profile']);

        $this->actingAs($company['user'])
            ->withSession(phaseTwoPasswordSession())
            ->post(route('agt.connection-checks.store'))
            ->assertRedirect(route('agt.connection.show'))
            ->assertSessionHas('error', 'A AGT recusou as credenciais de acesso.');

        $check = AgtConnectionCheck::query()->firstOrFail();

        expect($check->status)->toBe(AgtConnectionCheckStatus::Failed)
            ->and($check->http_status)->toBe(401)
            ->and($check->safe_message)->not->toContain('never expose')
            ->and($check->toArray())->not->toContain('agt-hml-password')
            ->and(AgtConnection::query()->firstOrFail()->status)->toBe(AgtConnectionStatus::Failed);
    } finally {
        File::deleteDirectory($keys['directory']);
    }
});

test('the database rejects AGT connections attached across tenant boundaries', function () {
    $first = phaseTwoCompany();
    $second = phaseTwoCompany();

    expect(fn () => AgtConnection::factory()->create([
        'workspace_id' => $second['workspace']->id,
        'legal_entity_id' => $first['legal_entity']->id,
        'environment' => AgtEnvironment::Homologation,
    ]))->toThrow(QueryException::class);
});
