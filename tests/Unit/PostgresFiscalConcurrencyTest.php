<?php

use App\Actions\ConvertRecurringProfile;
use App\Actions\GenerateRecurringInvoices;
use App\Actions\IssueFiscalDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\FiscalDocumentType;
use App\FiscalSeriesStatus;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Separate mandatory PostgreSQL CI gate.');
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->artisan('migrate:rollback', ['--step' => 8, '--force' => true])->assertExitCode(0);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();
    app()->instance(JwsSigner::class, new class implements JwsSigner
    {
        public function sign(array $payload, string $keyReference): string
        {
            return 'test.signature.bytes';
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    });
});

/** @return array{user: User, entity: LegalEntity, series: FiscalSeries, establishment: Establishment} */
function pgFiscalCompany(): array
{
    $user = User::factory()->withWorkspace()->create();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->headOffice()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $connection = AgtConnection::factory()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'status' => AgtConnectionStatus::Verified,
        'software_key_reference' => 'software/test', 'taxpayer_key_reference' => 'taxpayer/test',
        'software_key_fingerprint' => hash('sha256', 'software/test'), 'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer/test'),
    ]);
    $series = FiscalSeries::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'establishment_id' => $establishment->id, 'agt_connection_id' => $connection->id]);

    return compact('user', 'entity', 'series', 'establishment');
}

function pgProfile(array $company): array
{
    return [
        'document_type' => 'FT', 'document_date' => now()->toDateString(), 'due_date' => null, 'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id, 'customer_public_id' => null,
        'customer' => ['name' => 'Concurrency client', 'tax_identification_number' => '5411111111', 'country_code' => 'AO', 'address_line' => null],
        'notes' => null, 'references_document_public_id' => null, 'adjustment_reason' => null,
        'lines' => [['operation_type' => 'SG', 'operation_date' => null, 'product_code' => 'SERVICE', 'product_description' => 'Service', 'quantity' => '1', 'unit_of_measure' => 'UN', 'unit_price' => '100', 'discount_percentage' => '0', 'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'tax_exemption_code' => null]],
    ];
}

function pgDraft(array $company): FiscalDocument
{
    return app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], pgProfile($company));
}

/** @return list<array<string, mixed>> */
function pgContend(Closure $operation, int $workers = 3): array
{
    $directory = sys_get_temp_dir().'/facturac-contention-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork test worker');
            }
            if ($pid === 0) {
                DB::purge();
                file_put_contents("$directory/ready-$i", 'ready');
                $deadline = microtime(true) + 20;
                while (! file_exists("$directory/start")) {
                    if (microtime(true) > $deadline) {
                        exit(2);
                    }
                    usleep(1000);
                }
                try {
                    $result = ['ok' => true, 'value' => $operation($i)];
                } catch (Throwable $exception) {
                    $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
                }
                file_put_contents("$directory/result-$i", json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $pids[] = $pid;
        }
        $deadline = microtime(true) + 30;
        while (count(glob("$directory/ready-*")) !== $workers) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach barrier');
            }
            usleep(1000);
        }
        file_put_contents("$directory/start", 'start');
        foreach ($pids as $pid) {
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Worker timed out');
                }
                usleep(1000);
            }
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(fn (int $i): array => json_decode(file_get_contents("$directory/result-$i"), true, flags: JSON_THROW_ON_ERROR), range(0, $workers - 1));
    } finally {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::purge();
    }
}

test('postgres serializes different drafts in one series without losing committed numbers', function () {
    $company = pgFiscalCompany();
    $foreign = pgFiscalCompany();
    $drafts = [pgDraft($company)->id, pgDraft($company)->id, pgDraft($company)->id];
    $results = pgContend(fn (int $i) => app(IssueFiscalDocument::class)->execute(FiscalDocument::findOrFail($drafts[$i]), $company['user'], $company['series']->public_id, 1)->id);
    expect(array_column($results, 'ok'))->toBe([true, true, true]);
    expect(FiscalDocument::whereIn('id', $drafts)->orderBy('issue_sequence')->pluck('issue_sequence')->all())->toBe([1, 2, 3]);
    expect(AgtSubmission::count())->toBe(3)->and($company['series']->fresh()->next_number)->toBe(4)
        ->and($foreign['series']->fresh()->next_number)->toBe(1);
});

test('postgres duplicate issuance attempts consume one sequence and rollback permits deterministic retry', function () {
    $company = pgFiscalCompany();
    $draft = pgDraft($company);
    $results = pgContend(fn () => app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, 1)->id);
    expect(count(array_filter($results, fn ($result) => $result['ok'])))->toBe(1)
        ->and(AgtSubmission::count())->toBe(1)->and($company['series']->fresh()->next_number)->toBe(2);
    $next = pgDraft($company);
    try {
        DB::transaction(function () use ($company, $next): void {
            app(IssueFiscalDocument::class)->execute($next, $company['user'], $company['series']->public_id, 1);
            throw new RuntimeException('crash before commit');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('crash before commit');
    }
    expect($next->fresh()->document_no)->toBeNull()->and($company['series']->fresh()->next_number)->toBe(2);
    app(IssueFiscalDocument::class)->execute($next, $company['user'], $company['series']->public_id, 1);
    expect($next->fresh()->issue_sequence)->toBe(2)->and(AgtSubmission::count())->toBe(2);
});

test('postgres concurrent recurring operations return one logical occurrence and one document', function () {
    $company = pgFiscalCompany();
    $customer = Customer::factory()->create(['workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id]);
    $profile = RecurringInvoice::factory()->create(['workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id, 'establishment_id' => $company['establishment']->id, 'customer_id' => $customer->id, 'created_by_user_id' => $company['user']->id]);
    $results = pgContend(fn () => app(ConvertRecurringProfile::class)->execute($profile, CarbonImmutable::now())->id);
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and(count(array_unique(array_column($results, 'value'))))->toBe(1)
        ->and(DB::table('recurring_occurrences')->count())->toBe(1)->and(FiscalDocument::count())->toBe(1);
});

test('postgres row locks exclude overlapping critical sections', function () {
    $company = pgFiscalCompany();
    $results = pgContend(fn () => DB::transaction(function () use ($company): array {
        FiscalSeries::query()->whereKey($company['series']->id)->lockForUpdate()->firstOrFail();
        $start = microtime(true);
        usleep(100000);

        return ['start' => $start, 'end' => microtime(true)];
    }));
    expect(array_column($results, 'ok'))->toBe([true, true, true]);
    $intervals = array_column($results, 'value');
    usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);
    expect($intervals[1]['start'])->toBeGreaterThanOrEqual($intervals[0]['end'])
        ->and($intervals[2]['start'])->toBeGreaterThanOrEqual($intervals[1]['end']);
});

test('postgres worker death before commit rolls back fiscal evidence and sequence', function () {
    $company = pgFiscalCompany();
    $draft = pgDraft($company);
    $marker = sys_get_temp_dir().'/facturac-crash-'.bin2hex(random_bytes(8));
    DB::purge();
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        DB::beginTransaction();
        app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, 1);
        file_put_contents($marker, 'uncommitted');
        sleep(20);
        exit(2);
    }
    expect($pid)->toBeGreaterThan(0);
    try {
        $deadline = microtime(true) + 15;
        while (! file_exists($marker)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker did not reach crash point');
            }
            usleep(1000);
        }
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        DB::purge();
        app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, 1);
        expect(AgtSubmission::count())->toBe(1)->and($draft->fresh()->issue_sequence)->toBe(1)
            ->and($company['series']->fresh()->next_number)->toBe(2);
    } finally {
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        if (file_exists($marker)) {
            unlink($marker);
        }
    }
});

test('postgres simultaneous scheduler triggers advance one recurring cursor once', function () {
    $company = pgFiscalCompany();
    $customer = Customer::factory()->create(['workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id]);
    $profile = RecurringInvoice::factory()->create(['workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id, 'establishment_id' => $company['establishment']->id, 'customer_id' => $customer->id, 'created_by_user_id' => $company['user']->id]);
    $results = pgContend(fn () => app(GenerateRecurringInvoices::class)->execute());
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and(array_sum(array_map(fn ($result) => $result['value']['generated'], $results)))->toBe(1)
        ->and(DB::table('recurring_occurrences')->count())->toBe(1)
        ->and($profile->fresh()->generated_count)->toBe(1)
        ->and(FiscalDocument::count())->toBe(1);
});

function pgReceipt(array $company, FiscalDocument $source, int $amount): FiscalDocument
{
    return app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], array_replace(pgProfile($company), [
        'document_type' => 'RG', 'lines' => [], 'payment_method' => 'NU', 'payment_date' => now()->toDateString(),
        'settlements' => [['document_public_id' => $source->public_id, 'amount_minor' => $amount]],
    ]));
}

function pgReceiptSeries(array $company, string $code = 'RGTEST'): FiscalSeries
{
    $series = $company['series']->replicate();
    $series->fill(['series_code' => $code, 'document_type' => FiscalDocumentType::Receipt, 'next_number' => 1, 'last_issued_number' => null]);
    $series->save();

    return $series;
}

test('postgres concurrent full receipts across separate series cannot over-settle or replay a settlement', function () {
    $company = pgFiscalCompany();
    $foreign = pgFiscalCompany();
    $source = pgDraft($company);
    app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, 1);
    recordAuthoritativeAgtAcceptance($source->refresh());
    $series = [pgReceiptSeries($company, 'RGONE'), pgReceiptSeries($company, 'RGTWO')];
    $drafts = [pgReceipt($company, $source, 11400), pgReceipt($company, $source, 11400)];
    $results = pgContend(fn (int $i) => app(IssueFiscalDocument::class)->execute($drafts[$i], $company['user'], $series[$i]->public_id, 1)->id, 2);
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1);
    $failure = collect($results)->firstWhere('ok', false);
    expect($failure['error'])->toBe(FiscalFinalizationBlocked::class);
    $issued = FiscalDocument::where('document_type', 'RG')->whereNotNull('document_no')->firstOrFail();
    expect($issued->gross_total_minor)->toBe(11400)
        ->and(FiscalDocument::where('document_type', 'RG')->whereNotNull('document_no')->count())->toBe(1)
        ->and(AgtSubmission::count())->toBe(2)
        ->and($series[0]->fresh()->next_number + $series[1]->fresh()->next_number)->toBe(3)
        ->and($foreign['series']->fresh()->next_number)->toBe(1);
    expect(fn () => app(IssueFiscalDocument::class)->execute($issued, $company['user'], $issued->fiscalSeries->public_id, 1))->toThrow(FiscalFinalizationBlocked::class);
    expect(AgtSubmission::count())->toBe(2)->and($issued->fresh()->gross_total_minor)->toBe(11400);
});

test('postgres concurrent partial receipts allocate unique numbers and preserve total tax and settlement', function () {
    $company = pgFiscalCompany();
    $source = pgDraft($company);
    app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, 1);
    recordAuthoritativeAgtAcceptance($source->refresh());
    $series = pgReceiptSeries($company);
    $drafts = [pgReceipt($company, $source, 5700), pgReceipt($company, $source, 5700)];
    $results = pgContend(fn (int $i) => app(IssueFiscalDocument::class)->execute($drafts[$i], $company['user'], $series->public_id, 1)->id, 2);
    expect(array_column($results, 'ok'))->toBe([true, true]);
    $receipts = FiscalDocument::where('document_type', 'RG')->orderBy('issue_sequence')->get();
    expect($receipts->pluck('issue_sequence')->all())->toBe([1, 2])
        ->and($receipts->pluck('document_no')->unique()->count())->toBe(2)
        ->and($receipts->sum('gross_total_minor'))->toBe(11400)
        ->and($receipts->sum('tax_payable_minor'))->toBe(1400)
        ->and($series->fresh()->next_number)->toBe(3)->and(AgtSubmission::count())->toBe(3);
});

test('postgres draft update racing issuance has one winner and never overwrites frozen evidence', function () {
    $company = pgFiscalCompany();
    $draft = pgDraft($company);
    $results = pgContend(function (int $i) use ($company, $draft) {
        if ($i === 0) {
            return app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, 1)->id;
        }

        return app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], array_replace(pgProfile($company), ['notes' => 'Committed update']), $draft, 1)->id;
    }, 2);
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1);
    $fresh = $draft->fresh();
    if ($results[0]['ok']) {
        expect($results[1]['error'])->toBe(DomainException::class);
        expect($fresh->document_no)->not->toBeNull()->and($fresh->frozen_at)->not->toBeNull()->and($fresh->notes)->toBeNull()
            ->and(AgtSubmission::count())->toBe(1)->and($company['series']->fresh()->next_number)->toBe(2);
    } else {
        expect($results[0]['error'])->toBe(FiscalFinalizationBlocked::class);
        expect($fresh->revision)->toBe(2)->and($fresh->notes)->toBe('Committed update')->and($fresh->document_no)->toBeNull()
            ->and(AgtSubmission::count())->toBe(0)->and($company['series']->fresh()->next_number)->toBe(1);
    }
});

test('postgres simultaneous updates to one revision commit exactly one write with explicit stale conflict', function () {
    $company = pgFiscalCompany();
    $draft = pgDraft($company);
    $results = pgContend(fn (int $i) => app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'],
        array_replace(pgProfile($company), ['notes' => "Writer $i"]), $draft, 1)->notes, 2);
    $winners = array_values(array_filter($results, fn ($r) => $r['ok']));
    expect($winners)->toHaveCount(1)
        ->and(collect($results)->firstWhere('ok', false)['error'])->toBe(ValidationException::class)
        ->and($draft->fresh()->notes)->toBe($winners[0]['value'])->and($draft->fresh()->revision)->toBe(2)
        ->and($draft->fresh()->lines()->count())->toBe(1)->and(AgtSubmission::count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);
});

test('postgres series closure racing issuance leaves a valid terminal series without lost numbering', function () {
    $company = pgFiscalCompany();
    $draft = pgDraft($company);
    $results = pgContend(function (int $i) use ($company, $draft) {
        if ($i === 0) {
            return app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, 1)->id;
        }

        return DB::transaction(function () use ($company): bool {
            $series = FiscalSeries::query()->lockForUpdate()->findOrFail($company['series']->id);

            return $series->update(['status' => FiscalSeriesStatus::Closed]);
        });
    }, 2);
    expect($results[1]['ok'])->toBeTrue()->and($company['series']->fresh()->status)->toBe(FiscalSeriesStatus::Closed);
    if ($results[0]['ok']) {
        expect($draft->fresh()->issue_sequence)->toBe(1)->and(AgtSubmission::count())->toBe(1)
            ->and($company['series']->fresh()->next_number)->toBe(2);
    } else {
        expect($results[0]['error'])->toBe(FiscalFinalizationBlocked::class);
        expect($draft->fresh()->document_no)->toBeNull()->and(AgtSubmission::count())->toBe(0)
            ->and($company['series']->fresh()->next_number)->toBe(1);
    }
});

test('postgres populated environment migration refuses contradictions and preserves frozen evidence during backfill', function () {
    $migration = require database_path('migrations/2026_10_07_111342_bind_fiscal_environment_identity.php');
    $migration->down();
    $series = FiscalSeries::withoutEvents(fn () => FiscalSeries::factory()->create());
    $production = AgtConnection::factory()->create(['workspace_id' => $series->workspace_id, 'legal_entity_id' => $series->legal_entity_id, 'environment' => AgtEnvironment::Production]);
    $attributes = FiscalDocument::factory()->issued()->make(['workspace_id' => $series->workspace_id, 'legal_entity_id' => $series->legal_entity_id,
        'establishment_id' => $series->establishment_id, 'agt_connection_id' => $production->id, 'fiscal_series_id' => $series->id,
        'document_jws' => 'frozen.signature', 'document_payload_sha256' => hash('sha256', 'frozen')])->getAttributes();
    unset($attributes['environment']);
    $attributes['public_id'] = (string) Str::ulid();
    $known = DB::table('fiscal_documents')->insertGetId($attributes);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Conflicting environment evidence');
    expect(Schema::hasColumn('fiscal_documents', 'environment'))->toBeFalse();
    DB::table('fiscal_documents')->where('id', $known)->update(['agt_connection_id' => $series->agt_connection_id]);
    $attributes['public_id'] = (string) Str::ulid();
    $attributes['document_no'] = 'FT LEGACY/2';
    $attributes['agt_connection_id'] = null;
    $attributes['fiscal_series_id'] = null;
    $unknown = DB::table('fiscal_documents')->insertGetId($attributes);
    $before = DB::table('fiscal_documents')->where('id', $known)->first();
    DB::transaction(fn () => $migration->up());
    $after = DB::table('fiscal_documents')->where('id', $known)->first();
    expect($after->environment)->toBe('homologation');
    unset($after->environment);
    expect((array) $after)->toBe((array) $before)
        ->and(DB::table('fiscal_documents')->where('id', $unknown)->value('environment'))->toBe('unresolved');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(fn () => DB::transaction(fn () => DB::table('fiscal_documents')->where('id', $known)->update(['environment' => 'production'])))
        ->toThrow(QueryException::class);
    expect(DB::table('fiscal_documents')->where('id', $known)->value('environment'))->toBe('homologation');
});
