<?php

use App\Actions\CancelDataImport;
use App\Actions\ExportWorkspaceData;
use App\Analytics\TransactionReport;
use App\Analytics\TransactionWorkbook;
use App\Jobs\GenerateTransactionExport;
use App\Models\DataImport;
use App\Models\LegalEntity;
use App\Models\TransactionExport;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    $this->backingDisk = Storage::fake('remote');
    $remote = new class($this->backingDisk->getDriver(), $this->backingDisk->getAdapter()) extends FilesystemAdapter
    {
        public function path($path)
        {
            throw new LogicException('Object storage has no local paths.');
        }
    };
    Storage::set('remote', $remote);
    config(['filesystems.private_disk' => 'remote', 'backup.disk' => 'remote']);
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace()->firstOrFail();
    $this->company = LegalEntity::factory()->configured()->create(['workspace_id' => $this->workspace->id]);
});

test('workspace archives stream to the configured private disk and download with authorization', function () {
    $path = app(ExportWorkspaceData::class)->execute($this->workspace, $this->owner);
    $archive = new ZipArchive;
    expect($archive->open($this->backingDisk->path($path)))->toBeTrue()
        ->and($archive->locateName('clientes.csv'))->not->toBeFalse();
    $archive->close();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('settings.account.export'))->assertOk()->assertDownload();
});

test('remote workbook publication needs no local disk path and expires normally', function () {
    $export = TransactionExport::factory()->create([
        'workspace_id' => $this->workspace->id, 'legal_entity_id' => $this->company->id,
        'user_id' => $this->owner->id, 'document_ids' => [],
        'filters' => ['title' => 'Remote', 'from' => '2026-01-01', 'to' => '2026-12-31',
            'columns' => TransactionReport::DEFAULT_COLUMNS, 'types' => [], 'statuses' => [],
            'include_lines' => false, 'include_payments' => false],
        'expires_at' => now()->addDay(),
    ]);
    (new GenerateTransactionExport($export->id))->handle(app(TransactionWorkbook::class));
    expect($export->refresh()->status)->toBe('ready');
    $archive = new ZipArchive;
    expect($archive->open($this->backingDisk->path($export->storagePath())))->toBeTrue()
        ->and($archive->locateName('[Content_Types].xml'))->not->toBeFalse();
    $archive->close();
    $this->actingAs($this->owner)->get(route('transaction-reports.file', $export))->assertDownload();
    $export->update(['expires_at' => now()->subMinute()]);
    $this->getJson(route('transaction-reports.file', $export))->assertGone();
    $this->artisan('reports:prune-exports')->assertSuccessful();
    $this->backingDisk->assertMissing($export->storagePath());
});

test('failed remote uploads never report a completed workspace export', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('writeStream')->once()->andReturnFalse();
    Storage::set('remote', $disk);
    expect(fn () => app(ExportWorkspaceData::class)->execute($this->workspace, $this->owner))
        ->toThrow(RuntimeException::class, 'guardar');
});

test('logos remain private and preserve the old logo when remote upload fails', function () {
    $this->actingAs($this->owner)->post(route('company.logo.store'), [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertSessionHasNoErrors();
    $path = $this->company->refresh()->logo_path;
    $this->backingDisk->assertExists($path);
    $this->get(route('company.logo.show'))->assertOk();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
    Storage::set('remote', $disk);
    $this->post(route('company.logo.store'), ['logo' => UploadedFile::fake()->image('replacement.png')])
        ->assertSessionHasErrors('logo');
    expect($this->company->refresh()->logo_path)->toBe($path);
    $this->backingDisk->assertExists($path);
});

test('PostgreSQL backup includes remote originals and keeps the password out of arguments', function () {
    config(['backup.connection' => 'durable_test', 'database.connections.durable_test' => [
        'driver' => 'pgsql', 'host' => 'database.internal', 'port' => 5432,
        'database' => 'facturac', 'username' => 'backup', 'password' => 'test-secret', 'sslmode' => 'require',
    ]]);
    $this->backingDisk->put('logos/original.png', 'logo-bytes');
    $this->backingDisk->put('imports/original.csv', 'name,amount');
    $this->backingDisk->put('exports/excluded.zip', 'derived');
    Process::fake(['*' => Process::result(output: '-- PostgreSQL database dump')]);
    $this->artisan('backup:run')->assertSuccessful();
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === 'pg_dump'
        && ! str_contains(implode(' ', $process->command), 'test-secret')
        && $process->environment['PGPASSWORD'] === 'test-secret'
        && $process->environment['PGSSLMODE'] === 'require');
    $files = $this->backingDisk->files('backups');
    expect($files)->toHaveCount(1);
    $archive = new ZipArchive;
    $archive->open($this->backingDisk->path($files[0]));
    expect($archive->getFromName('base-de-dados.sql'))->toBe("-- PostgreSQL database dump\n")
        ->and($archive->getFromName('ficheiros/logos/original.png'))->toBe('logo-bytes')
        ->and($archive->getFromName('ficheiros/imports/original.csv'))->toBe('name,amount')
        ->and($archive->locateName('ficheiros/exports/excluded.zip'))->toBeFalse();
    $archive->close();
});

test('imports retain the configured remote disk for staging and cancellation', function () {
    $this->owner->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('["recovery"]'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($this->owner)->post(route('imports.store'), [
        'type' => 'customers', 'source' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('customers.csv', "nome,nif\nCliente,5000000001\n"),
    ])->assertSessionHasNoErrors();
    $import = DataImport::query()->sole();
    expect($import->storage_disk)->toBe('remote');
    $this->backingDisk->assertExists($import->storage_path);
    app(CancelDataImport::class)->execute($import, $this->owner);
    $this->backingDisk->assertMissing($import->storage_path);
});

test('a failed remote workbook write remains unavailable and can be retried', function () {
    $export = TransactionExport::factory()->create([
        'workspace_id' => $this->workspace->id, 'legal_entity_id' => $this->company->id,
        'user_id' => $this->owner->id,
    ]);
    $remote = Storage::disk('remote');
    $failing = Mockery::mock(FilesystemAdapter::class);
    $failing->shouldReceive('writeStream')->once()->andReturnFalse();
    Storage::set('remote', $failing);
    $job = new GenerateTransactionExport($export->id);
    expect(fn () => $job->handle(app(TransactionWorkbook::class)))->toThrow(RuntimeException::class, 'publish');
    expect($export->refresh()->status)->toBe('processing');
    $this->actingAs($this->owner)->getJson(route('transaction-reports.file', $export))->assertConflict();
    Storage::set('remote', $remote);
    $job->handle(app(TransactionWorkbook::class));
    expect($export->refresh()->status)->toBe('ready');
    $this->backingDisk->assertExists($export->storagePath());
});
