<?php

use App\Actions\DeleteUserAccount;
use App\AgtEnvironment;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Fiscal\IntegrationReadContext;
use App\Fiscal\IssuedIntegrationCredential;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true]);
});

afterEach(function () {
    if (! app()->environment('testing') || ! ((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:')
        || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))) {
        return;
    }
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

/** @return array{user: User, entity: LegalEntity, request: Request, context: IntegrationManagementContext} */
function integrationManagementFixture(): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $context = IntegrationManagementContext::resolve($request, $entity->workspace_id);

    return compact('user', 'entity', 'request', 'context');
}

function createTestIntegration(array $fixture): IssuedIntegrationCredential
{
    return app(IntegrationCredentials::class)->create($fixture['context'], $fixture['entity']->public_id, AgtEnvironment::Homologation, 'Software');
}

test('lifecycle secrets are disclosed once after commit and only digests enter storage and logs', function () {
    $fixture = integrationManagementFixture();
    DB::enableQueryLog();
    $issued = createTestIntegration($fixture);
    $secret = $issued->revealOnce();
    $credential = IntegrationCredential::where('public_id', $issued->credentialPublicId)->firstOrFail();
    expect($secret)->toMatch('/^fcr1\.[0-7][0-9A-HJKMNP-TV-Z]{25}\.[A-Za-z0-9_-]{43}$/')
        ->and($credential->secret_hash)->toBe(hash('sha256', $secret))
        ->and(json_encode(DB::getQueryLog()))->not->toContain($secret)->not->toContain($credential->secret_hash)
        ->and(json_encode(Activity::all()))->not->toContain($secret)->not->toContain($credential->secret_hash)
        ->and($credential->toArray())->not->toHaveKeys(['secret_hash', 'creator_attribution_id']);
    DB::disableQueryLog();
    expect(fn () => $issued->revealOnce())->toThrow(LogicException::class);
    expect(fn () => serialize($issued))->toThrow(LogicException::class);
    expect(fn () => clone $issued)->toThrow(LogicException::class);
    expect(fn () => serialize(IntegrationReadContext::authenticate($secret, (string) Str::uuid())))->toThrow(LogicException::class);
});

test('rotation serializes revision and preserves immutable creator identities and grant ceiling', function () {
    $fixture = integrationManagementFixture();
    $issued = createTestIntegration($fixture);
    $integration = Integration::where('public_id', $issued->integrationPublicId)->firstOrFail();
    $next = app(IntegrationCredentials::class)->rotate($fixture['context'], $integration->public_id, 1, $issued->credentialPublicId);
    expect(IntegrationCredential::count())->toBe(2)->and($integration->fresh()->revision)->toBe(2)
        ->and(IntegrationCredential::where('public_id', $issued->credentialPublicId)->firstOrFail()->expires_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue();
    expect(fn () => app(IntegrationCredentials::class)->rotate($fixture['context'], $integration->public_id, 1, $next->credentialPublicId))->toThrow(HttpException::class);
    expect(fn () => app(IntegrationCredentials::class)->rotate($fixture['context'], $integration->public_id, 2, $next->credentialPublicId))->toThrow(HttpException::class);
    expect(fn () => app(IntegrationCredentials::class)->create($fixture['context'], $fixture['entity']->public_id, AgtEnvironment::Homologation, 'Bad', ['*']))->toThrow(ValidationException::class);
});

test('management authority is rechecked from trusted session and current membership', function (string $condition) {
    $fixture = integrationManagementFixture();
    if ($condition === 'role') {
        WorkspaceMembership::where('user_id', $fixture['user']->id)->update(['role' => 'viewer']);
    }
    if ($condition === 'inactive') {
        WorkspaceMembership::where('user_id', $fixture['user']->id)->update(['is_active' => false]);
    }
    if ($condition === 'expired-session') {
        $fixture['request']->session()->put((string) config('work_session.started_at_key'), time() - 86400);
    }
    if ($condition === 'confirmation') {
        $fixture['request']->session()->put('auth.password_confirmed_at', time() - 301);
    }
    if ($condition === 'impersonation') {
        Context::add('impersonator_id', 123);
    }
    if ($condition === 'mfa') {
        $fixture['user']->forceFill(['two_factor_confirmed_at' => null])->save();
    }
    expect(fn () => createTestIntegration($fixture))->toThrow(HttpException::class);
    expect(Integration::count())->toBe(0)->and(IntegrationCredential::count())->toBe(0);
})->with(['role', 'inactive', 'expired-session', 'confirmation', 'impersonation', 'mfa']);

test('audit failure rolls back lifecycle creation and yields no secret or evidence', function () {
    $fixture = integrationManagementFixture();
    Activity::creating(fn () => throw new RuntimeException('Audit unavailable'));
    try {
        expect(fn () => createTestIntegration($fixture))->toThrow(RuntimeException::class);
    } finally {
        Activity::flushEventListeners();
    }
    expect(Integration::count())->toBe(0)->and(IntegrationCredential::count())->toBe(0);
});

test('deletion retains snapshots and cannot reconnect a newly created account or membership', function () {
    $fixture = integrationManagementFixture();
    $issued = createTestIntegration($fixture);
    $secret = $issued->revealOnce();
    $integration = Integration::firstOrFail();
    $credential = IntegrationCredential::firstOrFail();
    $identity = $fixture['user']->attribution_id;
    $id = $fixture['user']->id;
    DB::table('workspace_memberships')->where('user_id', $id)->delete();
    DB::table('users')->where('id', $id)->delete();
    expect($integration->fresh()->sponsor_user_id)->toBeNull()->and($integration->fresh()->sponsor_membership_id)->toBeNull()
        ->and($credential->fresh()->getAttribute('created_by_user_id'))->toBeNull()->and($credential->fresh()->creator_attribution_id)->toBe($identity);
    $new = User::factory()->create(['id' => $id, 'email' => $fixture['user']->email]);
    expect($new->attribution_id)->not->toBe($identity);
    $context = IntegrationReadContext::authenticate($secret, (string) Str::uuid());
    expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
});

test('raw snapshot and ownership updates are rejected while terminal revocation remains idempotent', function () {
    $fixture = integrationManagementFixture();
    $issued = createTestIntegration($fixture);
    expect(fn () => DB::table('integrations')->update(['creator_attribution_id' => (string) Str::uuid()]))->toThrow(QueryException::class);
    expect(fn () => DB::table('integration_credentials')->update(['creator_principal_kind' => 'agent']))->toThrow(QueryException::class);
    app(IntegrationCredentials::class)->revoke($fixture['context'], $issued->integrationPublicId, 1, 'compromise');
    app(IntegrationCredentials::class)->revoke($fixture['context'], $issued->integrationPublicId, 1, 'compromise');
    expect(Integration::firstOrFail()->revision)->toBe(2)->and(Activity::where('event', 'integration.revoked')->count())->toBe(1);
});

test('populated attribution migration preserves users and relationships and refuses evidence rollback', function () {
    $commandMigration = require database_path('migrations/2026_10_08_080150_add_external_customer_command_foundation.php');
    $commandMigration->down();
    $migration = require database_path('migrations/2026_10_07_123804_add_attribution_identity_to_users.php');
    $fixture = integrationManagementFixture();
    $document = FiscalDocument::factory()->issued()->create(['workspace_id' => $fixture['entity']->workspace_id, 'legal_entity_id' => $fixture['entity']->id, 'created_by_user_id' => $fixture['user']->id, 'document_payload_sha256' => hash('sha256', 'preserved-fiscal-evidence'), 'document_jws' => 'immutable.signature']);
    $fiscal = $document->fresh()->getAttributes();
    DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $fixture['user']->id, 'payload' => 'original-session-bytes', 'last_activity' => time()]);
    $attributes = $fixture['user']->fresh()->getAttributes();
    $identity = $attributes['attribution_id'];
    unset($attributes['attribution_id']);
    $integrationMigration = require database_path('migrations/2026_10_07_123805_create_integration_credentials.php');
    $integrationMigration->down();
    $migration->down();
    $migration->up();
    $integrationMigration->up();
    $commandMigration->up();
    $after = $fixture['user']->fresh()->getAttributes();
    unset($after['attribution_id']);
    expect($after)->toBe($attributes)->and($fixture['user']->fresh()->attribution_id)->not->toBe($identity)
        ->and(WorkspaceMembership::where('user_id', $fixture['user']->id)->exists())->toBeTrue();
    expect($document->fresh()->getAttributes())->toBe($fiscal)->and(DB::table('sessions')->where('id', 'existing-session')->value('payload'))->toBe('original-session-bytes');
    $fixture['user']->refresh();
    createTestIntegration($fixture);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('different administrator rotation snapshots actual issuer and preserves original attribution', function () {
    $f = integrationManagementFixture();
    $issued = createTestIntegration($f);
    $other = User::factory()->create();
    $other->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'user_id' => $other->id, 'role' => WorkspaceRole::Administrator, 'is_active' => true]);
    $f['request']->setUserResolver(fn () => $other);
    $context = IntegrationManagementContext::resolve($f['request'], $f['entity']->workspace_id);
    $next = app(IntegrationCredentials::class)->rotate($context, $issued->integrationPublicId, 1, $issued->credentialPublicId);
    expect(Integration::firstOrFail()->creator_attribution_id)->toBe($f['user']->attribution_id)
        ->and(IntegrationCredential::where('public_id', $issued->credentialPublicId)->firstOrFail()->creator_attribution_id)->toBe($f['user']->attribution_id)
        ->and(IntegrationCredential::where('public_id', $next->credentialPublicId)->firstOrFail()->creator_attribution_id)->toBe($other->attribution_id);
    $audit = Activity::where('event', 'integration.credential-created')->latest('id')->firstOrFail();
    expect($audit->causer_id)->toBe($other->id)->and($audit->properties['creator_attribution_id'])->toBe($other->attribution_id);
});

test('expiration replacement empty grants outer transactions and recent confirmation are separate lifecycle gates', function () {
    $f = integrationManagementFixture();
    $issued = createTestIntegration($f);
    expect(fn () => app(IntegrationCredentials::class)->replaceExpired($f['context'], $issued->integrationPublicId, 1))->toThrow(HttpException::class);
    DB::table('integration_credentials')->update(['created_at' => now()->subDays(2), 'expires_at' => now()->subDay()]);
    $next = app(IntegrationCredentials::class)->replaceExpired($f['context'], $issued->integrationPublicId, 1);
    app(IntegrationCredentials::class)->reduceGrant($f['context'], $issued->integrationPublicId, 2);
    $context = IntegrationReadContext::authenticate($next->revealOnce(), (string) Str::uuid());
    expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
    expect(fn () => DB::transaction(fn () => createTestIntegration($f)))->toThrow(HttpException::class);
    $f['request']->session()->forget('auth.password_confirmed_at');
    app(IntegrationCredentials::class)->revoke(IntegrationManagementContext::resolve($f['request'], $f['entity']->workspace_id), $issued->integrationPublicId, 3);
    expect(Integration::firstOrFail()->revoked_at)->not->toBeNull();
});

test('attribution staged retry preserves assigned values and rollback checks audit even without integration rows', function () {
    $commandMigration = require database_path('migrations/2026_10_08_080150_add_external_customer_command_foundation.php');
    $commandMigration->down();
    $migration = require database_path('migrations/2026_10_07_123804_add_attribution_identity_to_users.php');
    $f = integrationManagementFixture();
    $identity = $f['user']->attribution_id;
    $migration->up();
    expect($f['user']->fresh()->attribution_id)->toBe($identity);
    activity()->withProperties(['creator_attribution_id' => $identity])->log('Attribution evidence');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('scope aliases duplicates and wildcards are rejected without expanding the grant', function (array $scopes) {
    $f = integrationManagementFixture();
    expect(fn () => app(IntegrationCredentials::class)->create($f['context'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Bad scope', $scopes))->toThrow(ValidationException::class);
    expect(Integration::count())->toBe(0);
})->with([[['*']], [['admin']], [['write']], [['invoices:write']], [['invoices:read']], [['Documents:read']], [[' documents:read']], [['documents:read', 'documents:read']]]);

test('partial populated attribution retry preserves committed assignments and final UUID integrity', function () {
    $commandMigration = require database_path('migrations/2026_10_08_080150_add_external_customer_command_foundation.php');
    $commandMigration->down();
    $migration = require database_path('migrations/2026_10_07_123804_add_attribution_identity_to_users.php');
    $one = User::factory()->create();
    $two = User::factory()->create();
    $identity = $one->attribution_id;
    $migration->down();
    Schema::table('users', fn (Blueprint $table) => $table->uuid('attribution_id')->nullable()->unique());
    DB::table('users')->where('id', $one->id)->update(['attribution_id' => $identity]);
    $migration->up();
    expect($one->fresh()->attribution_id)->toBe($identity)->and($two->fresh()->attribution_id)->not->toBe($identity)->toMatch('/^[a-f0-9-]{36}$/');
    $attributes = $one->fresh()->getAttributes();
    $attributes['id'] = 999;
    $attributes['email'] = 'duplicate@example.com';
    expect(fn () => DB::transaction(fn () => DB::table('users')->insert($attributes)))->toThrow(QueryException::class);
    $attributes['attribution_id'] = null;
    expect(fn () => DB::transaction(fn () => DB::table('users')->insert($attributes)))->toThrow(QueryException::class);
});

test('existing retained-account anonymization preserves attribution and permanently removes sponsoring membership', function () {
    $f = integrationManagementFixture();
    $issued = createTestIntegration($f);
    $secret = $issued->revealOnce();
    $otherOwner = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $f['entity']->workspace_id, 'user_id' => $otherOwner->id, 'role' => WorkspaceRole::Owner, 'is_active' => true]);
    FiscalDocument::factory()->issued()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id, 'created_by_user_id' => $f['user']->id, 'issued_by_user_id' => $f['user']->id]);
    $identity = $f['user']->attribution_id;
    app(DeleteUserAccount::class)->execute($f['user']);
    expect($f['user']->fresh()->attribution_id)->toBe($identity)->and($f['user']->fresh()->name)->toBe('Utilizador removido')
        ->and(Integration::firstOrFail()->creator_attribution_id)->toBe($identity)->and(Integration::firstOrFail()->sponsor_membership_id)->toBeNull();
    expect(fn () => IntegrationReadContext::authenticate($secret, (string) Str::uuid())->authorize('documents.read'))->toThrow(HttpException::class);
});

test('empty additive schema rolls back and reapplies while populated integration rollback refuses', function () {
    $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true])->assertExitCode(0);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $f = integrationManagementFixture();
    createTestIntegration($f);
    $migration = require database_path('migrations/2026_10_07_123805_create_integration_credentials.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('review refuses lifecycle reveal when mandatory audit is disabled buffered or cancelled', function (string $mode) {
    $fixture = integrationManagementFixture();
    if ($mode === 'disabled') {
        activity()->disableLogging();
    } elseif ($mode === 'buffered') {
        config(['activitylog.buffer.enabled' => true]);
    } else {
        Activity::creating(fn () => false);
    }
    try {
        expect(fn () => createTestIntegration($fixture))->toThrow(RuntimeException::class);
        expect(Integration::count())->toBe(0)->and(IntegrationCredential::count())->toBe(0);
    } finally {
        activity()->enableLogging();
        config(['activitylog.buffer.enabled' => false]);
        Activity::flushEventListeners();
    }
})->with(['disabled', 'buffered', 'cancelled']);

test('review populated attribution backfill crosses chunk boundaries without changing existing user data', function () {
    $commandMigration = require database_path('migrations/2026_10_08_080150_add_external_customer_command_foundation.php');
    $commandMigration->down();
    $users = User::factory()->count(405)->create();
    $before = DB::table('users')->orderBy('id')->get()->map(function ($row): array {
        $attributes = (array) $row;
        unset($attributes['attribution_id']);

        return $attributes;
    })->all();
    $migration = require database_path('migrations/2026_10_07_123804_add_attribution_identity_to_users.php');
    $migration->down();
    $migration->up();
    $identities = DB::table('users')->orderBy('id')->pluck('attribution_id', 'id')->all();
    expect(count(array_unique($identities)))->toBe(405)->and(count(array_filter($identities, fn (string $id): bool => preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $id) === 1)))->toBe(405);
    $migration->up();
    expect(DB::table('users')->orderBy('id')->pluck('attribution_id', 'id')->all())->toBe($identities);
    $after = DB::table('users')->orderBy('id')->get()->map(function ($row): array {
        $attributes = (array) $row;
        unset($attributes['attribution_id']);

        return $attributes;
    })->all();
    expect($after)->toBe($before);
});

test('review account deletion refuses loss of the sole workspace holding integration evidence', function (bool $revoked) {
    $fixture = integrationManagementFixture();
    $issued = createTestIntegration($fixture);
    if ($revoked) {
        app(IntegrationCredentials::class)->revoke($fixture['context'], $issued->integrationPublicId, 1);
    }
    $deletion = app(DeleteUserAccount::class);
    $preview = $deletion->preview($fixture['user']);
    expect($preview['can_delete'])->toBeFalse()->and($preview['workspaces_deleted'])->toBe([]);
    expect(fn () => $deletion->execute($fixture['user']))->toThrow(BillingActionRefused::class);
    expect(Integration::count())->toBe(1)->and(IntegrationCredential::count())->toBe(1)
        ->and($fixture['user']->fresh())->not->toBeNull()
        ->and(WorkspaceMembership::where('user_id', $fixture['user']->id)->exists())->toBeTrue();
})->with([false, true]);

test('master scope lifecycle supports explicit production grants and preserves narrowing', function () {
    $f = integrationManagementFixture();
    $issued = app(IntegrationCredentials::class)->create($f['context'], $f['entity']->public_id, AgtEnvironment::Production, 'Master reads', ['customers:read', 'catalogue:read']);
    $secret = $issued->revealOnce();
    $integration = Integration::where('public_id', $issued->integrationPublicId)->firstOrFail();
    expect(DB::table('integration_scopes')->where('integration_id', $integration->id)->orderBy('scope')->pluck('scope')->all())->toBe(['catalogue:read', 'customers:read']);
    $rotated = app(IntegrationCredentials::class)->rotate($f['context'], $integration->public_id, 1, $issued->credentialPublicId, ['customers:read']);
    $context = IntegrationReadContext::authenticate($rotated->revealOnce(), (string) Str::uuid());
    $context->authorize('customers.read');
    expect(fn () => $context->authorize('catalogue.read'))->toThrow(HttpException::class);
    expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
    $original = IntegrationReadContext::authenticate($secret, (string) Str::uuid());
    $original->authorize('catalogue.read');
});

test('homologation lifecycle rejects new scopes before disclosure for creation rotation and expiry replacement', function (string $operation) {
    $f = integrationManagementFixture();
    $service = app(IntegrationCredentials::class);
    if ($operation === 'create') {
        expect(fn () => $service->create($f['context'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Denied', ['customers:read']))->toThrow(HttpException::class);
        expect(IntegrationCredential::count())->toBe(0);

        return;
    }
    $issued = createTestIntegration($f);
    $integration = Integration::where('public_id', $issued->integrationPublicId)->firstOrFail();
    if ($operation === 'rotate') {
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'catalogue:read']);
        DB::table('integration_credential_scopes')->insert(['credential_id' => IntegrationCredential::firstOrFail()->id, 'scope' => 'catalogue:read']);
        expect(fn () => $service->rotate($f['context'], $integration->public_id, 1, $issued->credentialPublicId, ['catalogue:read']))->toThrow(HttpException::class);
    } else {
        IntegrationCredential::firstOrFail()->forceFill(['created_at' => now()->subDays(2), 'expires_at' => now()->subDay()])->save();
        DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => 'customers:read']);
        expect(fn () => $service->replaceExpired($f['context'], $integration->public_id, 1))->toThrow(HttpException::class);
    }
    expect(IntegrationCredential::count())->toBe(1)->and($integration->fresh()->revision)->toBe(1);
})->with(['create', 'rotate', 'replace']);

test('master scope validators reject duplicates generic read and wildcard aliases', function (array $scopes) {
    $f = integrationManagementFixture();
    expect(fn () => app(IntegrationCredentials::class)->create($f['context'], $f['entity']->public_id, AgtEnvironment::Production, 'Invalid', $scopes))->toThrow(ValidationException::class);
    expect(Integration::count())->toBe(0);
})->with([[['customers:read', 'customers:read']], [['catalogue:*']], [['read-all']], [['Customers:read']], [[' customers:read']], [['customers:write']]]);

test('qualified backend rotation cannot widen retiring grants or parent ceiling', function () {
    try {
        $user = User::factory()->withWorkspace()->create();
        $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
        $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
        $request = Request::create('https://localhost/');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(config('work_session.started_at_key'), time());
        $request->session()->put('auth.password_confirmed_at', time());
        $context = IntegrationManagementContext::resolve($request, $entity->workspace_id);
        $issued = app(IntegrationCredentials::class)->create($context, $entity->public_id, AgtEnvironment::Homologation, 'Old scope', ['documents:read']);
        try {
            app(IntegrationCredentials::class)->rotate($context, $issued->integrationPublicId, 1, $issued->credentialPublicId, ['documents:agt-status:read']);
            $this->fail('Rotation widened the retiring grant.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
        expect(IntegrationCredential::count())->toBe(1);
        expect(DB::table('integration_scopes')->pluck('scope')->all())->toBe(['documents:read']);
    } finally {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }
});

test('billing scope lifecycle rotation preserves exact grants and original sponsor identity', function () {
    $f = integrationManagementFixture();
    $service = app(IntegrationCredentials::class);
    $issued = $service->create($f['context'], $f['entity']->public_id, AgtEnvironment::Production, 'Billing reads', ['analytics:billing:read']);
    $old = $issued->revealOnce();
    $rotated = $service->rotate($f['context'], $issued->integrationPublicId, 1, $issued->credentialPublicId, ['analytics:billing:read']);
    $new = $rotated->revealOnce();
    foreach ([$old, $new] as $secret) {
        $context = IntegrationReadContext::authenticate($secret, (string) Str::uuid());
        $context->authorize('analytics.billing.read');
        expect(fn () => $context->authorize('documents.read'))->toThrow(HttpException::class);
    }
    $membership = WorkspaceMembership::where('user_id', $f['user']->id)->where('workspace_id', $f['entity']->workspace_id)->firstOrFail();
    $membership->delete();
    WorkspaceMembership::factory()->create(['user_id' => $f['user']->id, 'workspace_id' => $f['entity']->workspace_id, 'role' => WorkspaceRole::Owner]);
    foreach ([$old, $new] as $secret) {
        expect(fn () => IntegrationReadContext::authenticate($secret, (string) Str::uuid())->authorize('analytics.billing.read'))->toThrow(HttpException::class);
    }
});

test('billing scope lifecycle refuses homologation create rotation and expiry replacement', function (string $operation) {
    $f = integrationManagementFixture();
    $service = app(IntegrationCredentials::class);
    if ($operation === 'create') {
        expect(fn () => $service->create($f['context'], $f['entity']->public_id, AgtEnvironment::Homologation, 'Denied', ['analytics:billing:read']))->toThrow(HttpException::class);
        expect(IntegrationCredential::count())->toBe(0);

        return;
    }
    $issued = createTestIntegration($f);
    $credential = IntegrationCredential::firstOrFail();
    DB::table('integration_scopes')->insert(['integration_id' => $credential->integration_id, 'scope' => 'analytics:billing:read']);
    if ($operation === 'rotate') {
        DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => 'analytics:billing:read']);
        expect(fn () => $service->rotate($f['context'], $issued->integrationPublicId, 1, $issued->credentialPublicId, ['analytics:billing:read']))->toThrow(HttpException::class);
    } else {
        $credential->forceFill(['created_at' => now()->subDays(2), 'expires_at' => now()->subDay()])->save();
        expect(fn () => $service->replaceExpired($f['context'], $issued->integrationPublicId, 1))->toThrow(HttpException::class);
    }
    expect(IntegrationCredential::count())->toBe(1);
})->with(['create', 'rotate', 'replace']);
