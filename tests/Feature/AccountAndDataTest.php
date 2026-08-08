<?php

use App\Actions\DeleteUserAccount;
use App\Actions\ExportWorkspaceData;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\ImpersonationSession;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, workspace: Workspace, legalEntity: LegalEntity, establishment: Establishment}
 */
function accountFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Conta')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'workspace', 'legalEntity', 'establishment');
}

/** @param array<string, mixed> $fixture */
function issuedDocument(array $fixture, ?User $issuer = null): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(100, 999),
        'issued_by_user_id' => $issuer?->id,
        'gross_total_minor' => 500_000,
        'document_payload_sha256' => hash('sha256', 'exemplo'),
    ]);
}

// ------------------------------------------------------- the printed document

test('an issued document can be opened and printed', function () {
    $fixture = accountFixture();
    $document = issuedDocument($fixture);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.print', $document))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Documents/Print')
            ->where('document.document_no', $document->document_no)
            // What makes the paper verifiable: the digest and a QR to reopen it.
            ->where('document.authenticity.digest', strtoupper(substr((string) $document->document_payload_sha256, 0, 8)))
            ->where('document.authenticity.qr_svg', fn (?string $svg): bool => is_string($svg) && str_contains($svg, '<svg'))
            ->where('shareUrl', fn (?string $url): bool => is_string($url))
        );
});

test('a draft has nothing to print', function () {
    $fixture = accountFixture();

    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    // No number, no signature, nothing filed — printing one would hand
    // someone a document that does not exist.
    $this->actingAs($fixture['owner'])
        ->get(route('documents.print', $draft))
        ->assertNotFound();
});

test('the customer opens the document with the signed link and nothing else', function () {
    $fixture = accountFixture();
    $document = issuedDocument($fixture);

    $signed = app(FiscalDocumentPresenter::class)->signedUrl($document);

    // The customer has no account; the signature is the whole authorisation.
    $this->get($signed)->assertOk();

    $this->get(route('documents.print', $document))->assertForbidden();
});

test('a tampered link is refused', function () {
    $fixture = accountFixture();
    $document = issuedDocument($fixture);

    $signed = app(FiscalDocumentPresenter::class)->signedUrl($document);

    $this->get($signed.'&total=1')->assertForbidden();
});

test('another company cannot open the document without a signature', function () {
    $fixture = accountFixture();
    $document = issuedDocument($fixture);

    $stranger = User::factory()->withWorkspace('Outra empresa')->create();

    $this->actingAs($stranger)
        ->get(route('documents.print', $document))
        ->assertForbidden();
});

// -------------------------------------------------------------- the sessions

test('the security page lists the device you are signed in on', function () {
    $fixture = accountFixture();

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('sessions'));
});

test('another session can be ended, and the current one cannot', function () {
    $fixture = accountFixture();

    DB::table('sessions')->insert([
        'id' => 'outra-sessao',
        'user_id' => $fixture['owner']->id,
        'ip_address' => '10.0.0.9',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
        'payload' => base64_encode(json_encode([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('settings.sessions.destroy', ['session' => 'outra-sessao']))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'outra-sessao')->exists())->toBeFalse();
});

test('ending the others leaves you signed in here', function () {
    $fixture = accountFixture();

    foreach (['um', 'dois'] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $fixture['owner']->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode(json_encode([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('settings.sessions.destroy-others'))
        ->assertRedirect();

    // Someone who has just realised their password is known should not be
    // signed out mid-decision.
    expect(DB::table('sessions')->whereIn('id', ['um', 'dois'])->count())->toBe(0)
        ->and($this->app['auth']->check())->toBeTrue();
});

test('one account cannot end another account session', function () {
    $fixture = accountFixture();
    $stranger = User::factory()->withWorkspace('Outra')->create();

    DB::table('sessions')->insert([
        'id' => 'sessao-alheia',
        'user_id' => $stranger->id,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode(json_encode([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('settings.sessions.destroy', ['session' => 'sessao-alheia']))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'sessao-alheia')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- the export

test('the export carries the records and none of the secrets', function () {
    Storage::fake('local');

    $fixture = accountFixture();

    Customer::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => 'Padaria Cazenga',
    ]);

    issuedDocument($fixture);

    $path = app(ExportWorkspaceData::class)
        ->execute($fixture['workspace'], $fixture['owner']);

    $archive = new ZipArchive;
    $archive->open(Storage::disk('local')->path($path));

    $names = [];

    for ($i = 0; $i < $archive->numFiles; $i++) {
        $names[] = $archive->getNameIndex($i);
    }

    $customers = (string) $archive->getFromName('clientes.csv');
    $documents = (string) $archive->getFromName('documentos.csv');
    $archive->close();

    expect($names)->toContain('clientes.csv', 'documentos.csv', 'artigos.csv', 'LEIA-ME.txt')
        ->and($customers)->toContain('Padaria Cazenga')
        // The signature travels so an exported document can be checked against
        // what was filed.
        ->and($documents)->toContain('assinatura_sha256')
        // Nothing that could be used to impersonate the company.
        ->and($customers)->not->toContain('password')
        ->and($documents)->not->toContain('taxpayer_key');
});

test('the export downloads as an archive', function () {
    Storage::fake('local');
    $fixture = accountFixture();

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('settings.account.export'))
        ->assertOk()
        ->assertDownload();
});

// -------------------------------------------------------------- the deletion

test('an account that issued nothing is deleted outright', function () {
    $fixture = accountFixture();

    app(DeleteUserAccount::class)->execute($fixture['owner']);

    expect(User::query()->whereKey($fixture['owner']->id)->exists())->toBeFalse()
        ->and(Workspace::query()->whereKey($fixture['workspace']->id)->exists())->toBeFalse();
});

test('an account that issued documents is stripped, not erased', function () {
    $fixture = accountFixture();

    // A co-owner keeps the company alive, so the documents outlive the person
    // who issued them — which is exactly when the row has to survive.
    $coOwner = User::factory()->create();
    $fixture['workspace']->memberships()->create([
        'user_id' => $coOwner->id,
        'role' => WorkspaceRole::Owner,
        'joined_at' => now(),
    ]);

    $document = issuedDocument($fixture, $fixture['owner']);

    app(DeleteUserAccount::class)->execute($fixture['owner']);

    $user = User::query()->whereKey($fixture['owner']->id)->first();

    // The law requires the document to be kept, and it names its issuer.
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Utilizador removido')
        ->and($user->email)->not->toContain('@vap')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($document->fresh()->issued_by_user_id)->toBe($user->id);
});

test('a sole owner with colleagues must hand the company over first', function () {
    $fixture = accountFixture();

    $colleague = User::factory()->create();
    $fixture['workspace']->memberships()->create([
        'user_id' => $colleague->id,
        'role' => WorkspaceRole::Accountant,
        'joined_at' => now(),
    ]);

    $preview = app(DeleteUserAccount::class)->preview($fixture['owner']);

    expect($preview['can_delete'])->toBeFalse()
        ->and($preview['blocking_workspaces'])->toContain($fixture['workspace']->name);

    expect(fn () => app(DeleteUserAccount::class)->execute($fixture['owner']))
        ->toThrow(BillingActionRefused::class);

    // Leaving a company's books with no owner is worse than making someone
    // hand them over.
    expect(Workspace::query()->whereKey($fixture['workspace']->id)->exists())->toBeTrue();
});

test('a company with another owner survives the departure', function () {
    $fixture = accountFixture();

    $coOwner = User::factory()->create();
    $fixture['workspace']->memberships()->create([
        'user_id' => $coOwner->id,
        'role' => WorkspaceRole::Owner,
        'joined_at' => now(),
    ]);

    app(DeleteUserAccount::class)->execute($fixture['owner']);

    expect(Workspace::query()->whereKey($fixture['workspace']->id)->exists())->toBeTrue();
});

test('deleting signs the person out and ends their sessions', function () {
    $fixture = accountFixture();

    DB::table('sessions')->insert([
        'id' => 'sessao-a-terminar',
        'user_id' => $fixture['owner']->id,
        'ip_address' => '10.0.0.2',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode(json_encode([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('settings.account.destroy'))
        ->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('id', 'sessao-a-terminar')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the account page says what deletion would cost before asking', function () {
    $fixture = accountFixture();

    // Old enough that the retention window has closed.
    issuedDocument($fixture, $fixture['owner'])
        ->forceFill(['document_date' => now()->subYears(9)->toDateString()])
        ->saveQuietly();

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.account'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Account')
            ->where('deletion.can_delete', true)
            ->where('deletion.retained_workspaces', [])
        );
});

// --------------------------------------------------------------- retention

test('a company holding recent documents cannot be deleted at all', function () {
    $fixture = accountFixture();
    issuedDocument($fixture, $fixture['owner']);

    $preview = app(DeleteUserAccount::class)->preview($fixture['owner']);

    // The obligation to keep them is the taxpayer's, and honouring a deletion
    // request by shredding what the law requires is not ours to do.
    expect($preview['can_delete'])->toBeFalse()
        ->and($preview['retained_workspaces'])->toHaveCount(1)
        ->and($preview['retained_workspaces'][0]['name'])->toBe($fixture['workspace']->name);

    expect(fn () => app(DeleteUserAccount::class)->execute($fixture['owner']))
        ->toThrow(BillingActionRefused::class, 'prazo legal de conservação');

    expect(Workspace::query()->whereKey($fixture['workspace']->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($fixture['owner']->id)->exists())->toBeTrue();
});

test('the window is counted from the end of the document year', function () {
    $fixture = accountFixture();
    $years = (int) config('fiscal.retention_years');

    // The last day still inside: five years after the end of that year.
    issuedDocument($fixture, $fixture['owner'])
        ->forceFill(['document_date' => now()->subYears($years)->startOfYear()->toDateString()])
        ->saveQuietly();

    $preview = app(DeleteUserAccount::class)->preview($fixture['owner']);

    expect($preview['can_delete'])->toBeFalse()
        ->and($preview['retained_workspaces'][0]['until'])
        ->toBe(now()->subYears($years)->endOfYear()->addYears($years)->toDateString());
});

test('a company whose window has closed can be deleted', function () {
    $fixture = accountFixture();

    $document = issuedDocument($fixture, $fixture['owner'])
        ->forceFill(['document_date' => now()->subYears(20)->toDateString()]);
    $document->saveQuietly();

    app(DeleteUserAccount::class)->execute($fixture['owner']);

    /*
     * The document has to go with the company, not merely stop being reachable.
     * It hangs off `establishments` through a constraint that only restricts,
     * so a workspace holding one is refused unless the deletion empties it
     * first — and the database is the only thing that will say so.
     */
    expect(Workspace::query()->whereKey($fixture['workspace']->id)->exists())->toBeFalse()
        ->and(FiscalDocument::query()->whereKey($document->id)->exists())->toBeFalse();
});

test('a support access record outlives the account it names', function () {
    $fixture = accountFixture();
    $support = User::factory()->create(['is_support_staff' => true]);

    $session = ImpersonationSession::factory()->create([
        'impersonator_id' => $support->id,
        'subject_id' => $fixture['owner']->id,
        'workspace_id' => $fixture['workspace']->id,
    ]);

    expect(app(DeleteUserAccount::class)->preview($fixture['owner'])['retains_support_record'])->toBeTrue();

    app(DeleteUserAccount::class)->execute($fixture['owner']);

    // Stripped, not erased: the row is evidence about the person who let
    // themselves in as much as about the one who has left.
    $fixture['owner']->refresh();

    expect(ImpersonationSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and($fixture['owner']->name)->toBe('Utilizador removido');
});

test('a draft never holds a company hostage', function () {
    $fixture = accountFixture();

    FiscalDocument::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    // Nothing was ever issued or filed, so there is nothing to retain.
    expect(app(DeleteUserAccount::class)->preview($fixture['owner'])['can_delete'])
        ->toBeTrue();
});

// ---------------------------------------------------------------- the backup

/**
 * The archive machinery is the subject here, not whichever dump tool the host
 * happens to have. Pointing at a file database keeps these deterministic on
 * both drivers; the mysqldump branch is covered by its own failure test below.
 */
function backupOnFileDatabase(): void
{
    $file = tempnam(sys_get_temp_dir(), 'vap-backup-test').'.sqlite';
    touch($file);

    // Only the backup is pointed elsewhere; the application's own connection
    // is left alone so the test database keeps working.
    config([
        'backup.disk' => 'local',
        'backup.connection' => 'backup_testing',
        'database.connections.backup_testing' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
        ],
    ]);
}

test('the backup command writes an archive that can be restored from', function () {
    Storage::fake('local');
    backupOnFileDatabase();

    $this->artisan('backup:run')->assertSuccessful();

    $files = Storage::disk('local')->files('backups');

    expect($files)->toHaveCount(1);

    $archive = new ZipArchive;
    $archive->open(Storage::disk('local')->path($files[0]));
    $readme = (string) $archive->getFromName('LEIA-ME.txt');
    $hasDump = $archive->locateName('base-de-dados.sql') !== false;
    $archive->close();

    // A backup nobody knows how to restore is not a backup.
    expect($hasDump)->toBeTrue()
        ->and($readme)->toContain('Para restaurar');
});

test('the backup keeps only as many archives as configured', function () {
    Storage::fake('local');
    backupOnFileDatabase();

    foreach (range(1, 3) as $index) {
        Storage::disk('local')->put("backups/backup-antigo-{$index}.zip", 'x');
    }

    $this->artisan('backup:run', ['--keep' => 2])->assertSuccessful();

    expect(Storage::disk('local')->files('backups'))->toHaveCount(2);
});

test('a backup that cannot reach the database says why', function () {
    Storage::fake('local');

    config([
        'backup.disk' => 'local',
        'backup.connection' => 'backup_unreachable',
        'database.connections.backup_unreachable' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'inexistente',
            'username' => 'ninguem',
            'password' => 'errada',
        ],
    ]);

    // "Install mysqldump" is the wrong advice when the tool is there and the
    // credentials are the problem, so the real reason has to come through.
    $this->artisan('backup:run')
        ->expectsOutputToContain('A exportação da base de dados falhou')
        ->assertFailed();

    expect(Storage::disk('local')->files('backups'))->toBeEmpty();
});
