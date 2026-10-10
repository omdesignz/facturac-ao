<?php

use App\Actions\IssueFiscalDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\Analytics\BillingSummaryQuery;
use App\Fiscal\AnalyticsCapabilities;
use App\Fiscal\BillingSummaryCommand;
use App\Fiscal\DocumentReadContext;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\IntegrationReadContext;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../BillingFixtures.php';

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory isolated PostgreSQL billing gate.');
    }
    expect(app()->environment())->toBe('testing')->and(DB::getDriverName())->toBe('pgsql')->and(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

function billingPgWait(string $path): void
{
    $deadline = microtime(true) + 30;
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Billing barrier timed out');
        }usleep(1000);
    }
}

/** @return list<mixed> */
function billingPgPair(Closure $operation, bool $expectKilledWorker = false): array
{
    $dir = sys_get_temp_dir().'/billing-pg-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Fork failed');
            }
            if ($pid === 0) {
                DB::purge();
                try {
                    $value = ['ok' => true, 'data' => $operation($worker, $dir)];
                } catch (Throwable $e) {
                    $value = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
                }file_put_contents($dir.'/result-'.$worker, json_encode($value, JSON_THROW_ON_ERROR));
                exit(0);
            }$pids[] = $pid;
        }
        foreach ([0, 1] as $worker) {
            billingPgWait($dir.'/result-'.$worker);
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if ($expectKilledWorker && $pid === $pids[0]) {
                expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
            } else {
                expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
            }
        }
        $result = array_map(fn ($i) => json_decode(file_get_contents($dir.'/result-'.$i), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        expect(array_column($result, 'ok'))->toBe([true, true], json_encode($result));

        return array_column($result, 'data');
    } finally {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }rmdir($dir);
    }
}

function billingPgContext(array $f): IntegrationReadContext
{
    return IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
}

test('PostgreSQL billing final review commits during the actual statement snapshot', function (string $operation) {
    $f = billingSummaryFixture();
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $profile = billingIssueProfile($f, date: '2024-02-01');
    $draft = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, $profile);
    $draft->update(['environment' => 'production']);
    billingPgBulk($f, 50001, '2024-02-10');
    DB::statement('ANALYZE fiscal_documents');
    $context = billingPgContext($f);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $before = app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    $lockKey = random_int(100000000, 200000000);
    $results = billingPgPair(function (int $worker, string $dir) use ($f, $user, $profile, $draft, $context, $command, $operation, $lockKey) {
        if ($worker === 0) {
            billingPgWait($dir.'/prepared');
            $backend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            file_put_contents($dir.'/reader', (string) $backend);
            $query = new class($lockKey, $draft->id) extends BillingSummaryQuery
            {
                public function __construct(private int $lockKey, private int $documentId) {}

                public function statement(DocumentReadContext $context, BillingSummaryCommand $command): array
                {
                    $statement = parent::statement($context, $command);
                    // A test-only, always-true barrier at one selected payload. All production predicates and bindings remain intact.
                    $needle = 'FROM fiscal_documents WHERE id = c.id';
                    expect(substr_count($statement['sql'], $needle))->toBe(1);
                    $statement['sql'] = str_replace($needle, $needle.' AND (c.id <> '.$this->documentId.' OR (SELECT 1 FROM pg_advisory_xact_lock('.$this->lockKey.')) = 1)', $statement['sql']);

                    return $statement;
                }
            };
            app()->instance(BillingSummaryQuery::class, $query);
            $data = app(AnalyticsCapabilities::class)->billingSummary($context, $command)['currencies'];

            return ['data' => $data, 'transaction_level' => DB::transactionLevel()];
        }

        DB::select('SELECT pg_advisory_lock(?)', [$lockKey]);
        try {
            DB::beginTransaction();
            if ($operation === 'delete') {
                $draft->delete();
            } elseif ($operation === 'move') {
                app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, billingIssueProfile($f, date: '2024-03-01'), $draft, $draft->revision);
            } elseif ($operation === 'insert') {
                billingIssue($f, $profile);
            } else {
                $changed = [...$profile, 'currency_code' => 'USD', 'exchange_rate_micro' => 900_000_000];
                $changed['lines'][0]['unit_price'] = '237';
                $saved = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, $changed, $draft, $draft->revision);
                $series = billingPrepareIssuance($f, 'FT');
                app(IssueFiscalDocument::class)->execute($saved, $user, $series->public_id, $saved->revision);
            }
            file_put_contents($dir.'/prepared', 'ready');
            billingPgWait($dir.'/reader');
            $backend = (int) file_get_contents($dir.'/reader');
            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne("SELECT COUNT(*) AS n FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted", [$backend])->n;
                if ((int) $waiting === 1) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            expect((int) $waiting)->toBe(1, 'Reader must be executing inside the SQL barrier before the writer finishes.');
            if ($operation === 'rollback') {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return ['observed_wait' => true];
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            DB::select('SELECT pg_advisory_unlock(?)', [$lockKey]);
        }
    });
    $after = app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    expect($results[0]['data'])->toBe($before)->and($results[0]['transaction_level'])->toBe(0)
        ->and($results[1]['observed_wait'])->toBeTrue();
    if (in_array($operation, ['insert', 'issue'], true)) {
        expect($after)->not->toBe($before);
        $currency = $operation === 'issue' ? 6 : 0;
        expect((int) $after[$currency]['invoiced_gross_minor'] - (int) $before[$currency]['invoiced_gross_minor'])->toBe($operation === 'issue' ? 27018 : 11400);
    } else {
        expect($after)->toBe($before);
    }
})->with(['insert', 'issue', 'move', 'delete', 'rollback']);

test('PostgreSQL billing Round 2 exact ordered mixed candidate boundaries', function (int $count) {
    $f = billingSummaryFixture();
    billingPgBulk($f, $count, '2024-02-10', mixed: true);
    billingPgBulk($f, 1, '2024-01-31', 300000);
    billingPgBulk($f, 1, '2024-03-01', 400000);
    DB::statement('ANALYZE fiscal_documents');
    $context = billingPgContext($f);
    $query = app(BillingSummaryQuery::class);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $statement = $query->statement($context, $command);
    billingAssertCandidateSequence($statement, $count);
    if ($count <= 100000) {
        $data = $query->read($context, $command);
        $expected = DB::select("SELECT currency_code,
            count(*) FILTER (WHERE status <> 'draft' AND document_type IN ('FT','FR','GF','ND')) AS billed,
            count(*) FILTER (WHERE status <> 'draft' AND document_type = 'NC') AS credited
            FROM fiscal_documents WHERE workspace_id = ? AND legal_entity_id = ? AND environment = ?
            AND document_date >= ? AND document_date < ? GROUP BY currency_code", array_slice($statement['bindings'], 0, 5));
        $expected = array_column($expected, null, 'currency_code');
        foreach ($data['currencies'] as $bucket) {
            $billed = (int) ($expected[$bucket['currency_code']]->billed ?? 0);
            $credited = (int) ($expected[$bucket['currency_code']]->credited ?? 0);
            expect($bucket['billed_document_count'])->toBe($billed)->and($bucket['credit_note_count'])->toBe($credited)
                ->and($bucket['invoiced_gross_minor'])->toBe((string) $billed)->and($bucket['credit_gross_minor'])->toBe((string) $credited);
        }
    } else {
        $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertServiceUnavailable()->assertJsonMissingPath('data');
        expect(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
    }
})->with([0, 1, 100, 49999, 50000, 50001, 99999, 100000, 100001, 200001]);

test('PostgreSQL billing Round 2 corrupt boundary and excluded physical overflow fail closed', function (int $position, bool $excluded) {
    $f = billingSummaryFixture();
    billingPgBulk($f, $excluded ? 100001 : max(100000, $position), '2024-02-10', excluded: $excluded, invalidPosition: $excluded ? null : $position);
    DB::statement('ANALYZE fiscal_documents');
    if (! $excluded) {
        $invalid = DB::table('fiscal_documents')->where('public_id', '01'.str_pad((string) $position, 24, '0', STR_PAD_LEFT))->first();
        expect($invalid->currency_code)->toBe('XXX')
            ->and(DB::table('fiscal_documents')->where('legal_entity_id', $f['entity']->id)->where('id', '<', $invalid->id)->count())->toBe($position - 1);
    }
    $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonMissingPath('data');
    $head = $this->withToken($f['secret'])->json('HEAD', $f['url'].'?month=2024-02')->assertServiceUnavailable();
    expect($head->getContent())->toBe('')->and(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0)
        ->and($f['credential']->fresh()->last_used_at)->toBeNull();
    $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with([50000, 50001, 100000, 100001])->with([false, true]);

test('PostgreSQL billing Round 2 draft null control and RG straddle both exact key boundaries', function (int $count) {
    $f = billingSummaryFixture();
    billingPgBulk($f, $count, '2024-02-10', boundaryControls: true);
    DB::statement('ANALYZE fiscal_documents');
    foreach ([50000 => 'draft', 50001 => 'issued', 100000 => 'draft'] as $position => $status) {
        $row = DB::table('fiscal_documents')->orderBy('document_date')->orderBy('id')->offset($position - 1)->first();
        expect($row->status)->toBe($status)->and($row->document_type)->toBe($position === 50001 ? 'RG' : 'FT');
    }
    $context = billingPgContext($f);
    $query = app(BillingSummaryQuery::class);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    billingAssertCandidateSequence($query->statement($context, $command), $count);
    if ($count === 100000) {
        $currencies = $query->read($context, $command)['currencies'];
        expect(array_sum(array_column($currencies, 'billed_document_count')))->toBe(99997)
            ->and(array_sum(array_map('intval', array_column($currencies, 'invoiced_gross_minor'))))->toBe(99997);
    } else {
        $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertServiceUnavailable()->assertJsonMissingPath('data');
    }
})->with([100000, 100001]);

test('PostgreSQL billing Round 2 fresh million distractors mixed backdated plan remains bounded after statistics refresh', function () {
    $f = billingSummaryFixture();
    billingPgBulk($f, 1000000, '2023-01-10');
    billingPgBulk($f, 100000, '2024-02-10', 1000000, mixed: true);
    $context = billingPgContext($f);
    $statement = app(BillingSummaryQuery::class)->statement($context, BillingSummaryCommand::fromMonth('2024-02'));
    $observations = [];
    foreach (range(0, 3) as $refresh) {
        DB::statement('ANALYZE fiscal_documents');
        $metadata = billingPgPlanMetadata();
        $plan = DB::transaction(function () use ($statement) {
            DB::statement('SET TRANSACTION READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '2000ms'");
            DB::statement("SET LOCAL lock_timeout = '250ms'");

            return json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['bindings'], useReadPdo: false)->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
        });
        $observations[] = compact('refresh', 'metadata', 'plan');
        file_put_contents(sys_get_temp_dir().'/phase4d-round2-runtime-plan-mixed.json', json_encode(compact('statement', 'observations'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        billingAssertPlan($plan, 100000);
    }
    billingAssertCandidateSequence($statement, 100000);
    $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk();
});

test('PostgreSQL billing Round 2 real domain commit rollback and draft movement snapshots', function (bool $readFirst, string $operation) {
    $f = billingSummaryFixture();
    $source = billingIssue($f, billingIssueProfile($f, date: $operation === 'draft' ? '2024-02-01' : '2024-02-10'));
    recordAuthoritativeAgtAcceptance($source);
    $user = User::findOrFail($f['integration']->sponsor_user_id);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, billingIssueProfile($f, date: '2024-03-01'));
    if ($operation === 'draft') {
        billingPgBulk($f, 50000, '2024-02-10', 400000);
        DB::statement('ANALYZE fiscal_documents');
    }
    $context = billingPgContext($f);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    $before = app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    $results = billingPgPair(function (int $worker, string $dir) use ($f, $source, $user, $draft, $context, $command, $readFirst, $operation) {
        if ($worker === 0) {
            billingPgWait($dir.($readFirst ? '/prepared' : '/committed'));
            $seen = 0;
            DB::connection()->beforeExecuting(function (string $sql) use (&$seen) {
                if (str_contains($sql, 'WITH candidates')) {
                    $seen++;
                }
            });
            $data = app(AnalyticsCapabilities::class)->billingSummary($context, $command)['currencies'];
            file_put_contents($dir.'/read', 'done');

            return ['data' => $data, 'hooks' => $seen];
        }
        DB::beginTransaction();
        match ($operation) {
            'issue', 'rollback' => billingIssue($f, billingIssueProfile($f)),
            'credit' => billingIssue($f, [...billingIssueProfile($f, 'NC'), 'references_document_public_id' => $source->public_id]),
            'receipt' => billingIssue($f, [...billingIssueProfile($f, 'RG'), 'lines' => [], 'settlements' => [['document_public_id' => $source->public_id, 'amount_minor' => 11400]]]),
            'poll' => recordAuthoritativeAgtAcceptance($source, 'I'),
            'rebuild' => AgtReconstruction::rebuild($source->submissions()->firstOrFail()->id),
            'draft' => (function () use ($f, $user, $draft) {
                $saved = app(SaveFiscalDocumentDraft::class)->execute($f['entity'], $user, billingIssueProfile($f, date: '2024-02-01'), $draft, $draft->revision);
                $series = billingPrepareIssuance($f, 'FT');
                app(IssueFiscalDocument::class)->execute($saved, $user, $series->public_id, $saved->revision);
            })(),
        };
        file_put_contents($dir.'/prepared', 'ready');
        if ($readFirst) {
            billingPgWait($dir.'/read');
        }
        if ($operation === 'rollback') {
            DB::rollBack();
        } else {
            DB::commit();
        }
        file_put_contents($dir.'/committed', 'done');

        return true;
    });
    $after = app(BillingSummaryQuery::class)->read($context, $command)['currencies'];
    expect($results[0]['hooks'])->toBe(1)->and($results[0]['data'])->toBe($readFirst ? $before : $after);
    expect($after[0]['invoiced_gross_minor'])->toBe((string) ((int) $before[0]['invoiced_gross_minor'] + (in_array($operation, ['issue', 'draft'], true) ? 11400 : 0)))
        ->and($after[0]['credit_gross_minor'])->toBe($operation === 'credit' ? '11400' : '0');
})->with([true, false])->with(['issue', 'credit', 'rollback', 'draft', 'receipt', 'poll', 'rebuild']);

test('PostgreSQL billing one statement snapshot sees complete concurrent invoice or credit commit', function (bool $first, string $type) {
    $f = billingSummaryFixture();
    billingDocument($f);
    $context = billingPgContext($f);
    $result = billingPgPair(function (int $worker, string $dir) use ($f, $context, $first, $type) {
        if ($worker === 0) {
            $paused = false;
            $pause = function (string $sql) use (&$paused, $dir) {
                if (! $paused && str_contains($sql, 'WITH candidates')) {
                    $paused = true;
                    file_put_contents($dir.'/snapshot', 'ready');
                    billingPgWait($dir.'/written');
                }
            };
            if ($first) {
                // PDO has materialized the complete result before the application folds it.
                $query = Mockery::mock(BillingSummaryQuery::class)->makePartial();
                $query->shouldReceive('read')->andReturnUsing(function ($ctx, $cmd) use ($dir) {
                    $data = (new BillingSummaryQuery)->read($ctx, $cmd);
                    file_put_contents($dir.'/snapshot', 'ready');
                    billingPgWait($dir.'/written');

                    return $data;
                });
                app()->instance(BillingSummaryQuery::class, $query);
            } else {
                DB::connection()->beforeExecuting($pause);
            }

            return app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'][0];
        }
        billingPgWait($dir.'/snapshot');
        billingDocument($f, ['document_type' => $type]);
        file_put_contents($dir.'/written', 'committed');

        return 'committed';
    });
    expect($result[0]['after_credits_gross_minor'])->toBe($first ? '10000' : ($type === 'NC' ? '0' : '20000'));
})->with([true, false])->with(['FT', 'NC']);

test('PostgreSQL billing current authority is revalidated before query and remains a bounded read snapshot', function (bool $after) {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $result = billingPgPair(function (int $worker, string $dir) use ($f, $context, $after) {
        if ($worker === 0) {
            $seen = 0;
            DB::connection()->beforeExecuting(function (string $sql) use (&$seen, $dir, $after) {
                if (($after && str_contains($sql, 'WITH candidates')) || (! $after && str_contains($sql, 'integration_credentials') && ++$seen === 1)) {
                    file_put_contents($dir.'/authority', 'ready');
                    billingPgWait($dir.'/withdrawn');
                }
            });
            try {
                app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));

                return 200;
            } catch (HttpException $e) {
                return $e->getStatusCode();
            }
        }
        billingPgWait($dir.'/authority');
        DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete();
        file_put_contents($dir.'/withdrawn', 'done');

        return 'done';
    });
    expect($result[0])->toBe($after ? 200 : 403);
    expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02')))->toThrow(HttpException::class);
})->with([true, false]);

test('PostgreSQL billing guard is shared across processes and released after statement failure', function () {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $results = billingPgPair(function (int $worker, string $dir) use ($context, $f) {
        if ($worker === 0) {
            $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
            expect($lock->get())->toBeTrue();
            file_put_contents($dir.'/locked', 'ready');
            billingPgWait($dir.'/denied');
            $lock->release();

            return true;
        }
        billingPgWait($dir.'/locked');
        try {
            app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));

            return 200;
        } catch (HttpException $e) {
            file_put_contents($dir.'/denied', 'done');

            return $e->getStatusCode();
        }
    });
    expect($results)->toBe([true, 429]);
    expect(app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'])->toHaveCount(8);
});

test('PostgreSQL billing read only and deadline settings are enforced and restored', function (string $injection) {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $triggered = false;
    $before = DB::selectOne("SELECT current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock, current_setting('transaction_read_only') AS readonly");
    DB::connection()->beforeExecuting(function (string $sql) use (&$triggered, $injection) {
        if (! $triggered && str_contains($sql, 'WITH candidates')) {
            $triggered = true;
            if ($injection === 'write') {
                DB::statement("INSERT INTO cache (key,value,expiration) VALUES ('forbidden-billing-write','x',0)");
            } else {
                DB::select('SELECT pg_sleep(3)');
            }
        }
    });
    expect(fn () => app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02')))->toThrow(RuntimeException::class);
    $after = DB::selectOne("SELECT current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock, current_setting('transaction_read_only') AS readonly");
    expect($after)->toEqual($before)->and(DB::connection()->transactionLevel())->toBe(0)->and(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
    $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with(['write', 'timeout']);

function billingPgBulk(array $f, int $count, string $date, int $offset = 0, bool $mixed = false, bool $excluded = false, ?int $invalidPosition = null, bool $boundaryControls = false): void
{
    $establishment = Establishment::query()->where('legal_entity_id', $f['entity']->id)->first() ?? Establishment::factory()->headOffice()->create(['workspace_id' => $f['entity']->workspace_id, 'legal_entity_id' => $f['entity']->id]);
    $type = $excluded ? "'RG'" : ($mixed ? "(ARRAY['FT','FR','GF','ND','NC','RG'])[((g-1)%6)+1]" : "'FT'");
    $status = $mixed ? "CASE WHEN g % 17 = 0 THEN 'draft' ELSE (ARRAY['issued','received','processing','valid','invalid','contingency'])[((g-1)%6)+1] END" : "'issued'";
    $currency = $invalidPosition === null ? "(ARRAY['AOA','BRL','CNY','EUR','GBP','NAD','USD','ZAR'])[((g-1)%8)+1]" : 'CASE WHEN g = '.$invalidPosition." THEN 'XXX' ELSE 'AOA' END";
    $dateExpression = $mixed ? "CASE g % 3 WHEN 0 THEN '2024-02-01'::date WHEN 1 THEN '2024-02-29'::date ELSE ?::date END" : '?::date';
    if ($boundaryControls) {
        $type = "CASE WHEN g IN (50001,100001) THEN 'RG' ELSE 'FT' END";
        $status = "CASE WHEN g IN (50000,100000) THEN 'draft' ELSE 'issued' END";
        $currency = "CASE WHEN g = 50000 THEN 'XXX' ELSE (ARRAY['AOA','BRL','CNY','EUR','GBP','NAD','USD','ZAR'])[((g-1)%8)+1] END";
    }
    DB::statement("INSERT INTO fiscal_documents (public_id,workspace_id,legal_entity_id,establishment_id,created_by_user_id,updated_by_user_id,document_type,status,document_no,document_date,currency_code,customer_name,customer_tax_identification_number,environment,net_total_minor,tax_payable_minor,gross_total_minor,issued_at,frozen_at,calculation_sha256)
        SELECT '01'||lpad((g+?)::text,24,'0'),?,?,?, ?,?, $type,$status,CASE WHEN ($status) = 'draft' THEN NULL ELSE 'FT PLAN/'||(g+?)::text END,$dateExpression,
            $currency, 'Plan fixture','5411111111',?,1,0,1,CASE WHEN ($status) = 'draft' THEN NULL ELSE NOW() END,CASE WHEN ($status) = 'draft' THEN NULL ELSE NOW() END,repeat('0',64)
        FROM generate_series(1,?) g", [$offset, $f['entity']->workspace_id, $f['entity']->id, $establishment->id, $f['integration']->sponsor_user_id, $f['integration']->sponsor_user_id, $offset, $date, $f['integration']->environment, $count]);
}

test('PostgreSQL billing Round 2 lock timeout and terminated connection cancel without publication', function (string $failure) {
    $f = billingSummaryFixture();
    billingDocument($f);
    $context = billingPgContext($f);
    $results = billingPgPair(function (int $worker, string $dir) use ($f, $context, $failure) {
        if ($worker === 1) {
            if ($failure === 'lock') {
                DB::beginTransaction();
                DB::statement('LOCK TABLE fiscal_documents IN ACCESS EXCLUSIVE MODE');
                file_put_contents($dir.'/locked', 'ready');
                billingPgWait($dir.'/cancelled');
                DB::rollBack();
            } else {
                billingPgWait($dir.'/pid');
                expect(DB::selectOne('SELECT pg_terminate_backend(?) AS killed', [(int) file_get_contents($dir.'/pid')])->killed)->toBeTrue();
                file_put_contents($dir.'/killed', 'done');
            }

            return true;
        }
        if ($failure === 'lock') {
            billingPgWait($dir.'/locked');
        }
        $seen = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$seen, $dir, $failure) {
            if (str_contains($sql, 'WITH candidates')) {
                $seen++;
                if ($failure === 'connection') {
                    file_put_contents($dir.'/pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    billingPgWait($dir.'/killed');
                }
            }
        });
        $started = microtime(true);
        try {
            app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));
            $failed = false;
        } catch (Throwable) {
            $failed = true;
        }
        $elapsed = microtime(true) - $started;
        file_put_contents($dir.'/cancelled', 'done');
        $transactions = DB::connection()->transactionLevel();
        DB::purge();
        $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
        $released = $lock->get();
        $lock->release();

        return compact('seen', 'failed', 'elapsed', 'transactions', 'released');
    });
    expect($results[0]['failed'])->toBeTrue()->and($results[0]['seen'])->toBe(1)
        ->and($results[0]['transactions'])->toBe(0)->and($results[0]['released'])->toBeTrue()
        ->and($results[0]['elapsed'])->toBeLessThan(2);
    if ($failure === 'lock') {
        expect($results[0]['elapsed'])->toBeGreaterThanOrEqual(0.25);
    }
    expect(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
    expect(app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'][0]['invoiced_gross_minor'])->toBe('10000');
})->with(['lock', 'connection']);

test('PostgreSQL billing Round 2 killed worker releases authority only through ten second lease recovery', function () {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $results = billingPgPair(function (int $worker, string $dir) use ($f, $context) {
        if ($worker === 0) {
            DB::connection()->beforeExecuting(function (string $sql) use ($dir) {
                if (str_contains($sql, 'WITH candidates')) {
                    file_put_contents($dir.'/dead-worker', (string) getmypid());
                    while (true) {
                        usleep(10000);
                    }
                }
            });
            app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));

            return false;
        }
        billingPgWait($dir.'/dead-worker');
        $pid = (int) file_get_contents($dir.'/dead-worker');
        expect(posix_kill($pid, SIGKILL))->toBeTrue();
        file_put_contents($dir.'/result-0', json_encode(['ok' => true, 'data' => 'killed'], JSON_THROW_ON_ERROR));
        try {
            app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'));
            $status = 200;
        } catch (HttpException $exception) {
            $status = $exception->getStatusCode();
        }
        expect($status)->toBe(429)->and(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0);
        $key = 'billing-query-workspace:'.$f['entity']->workspace_id;
        $store = Cache::store('database')->getStore();
        $expiration = DB::table('cache_locks')->where('key', $store->getPrefix().$key)->value('expiration');
        expect((int) $expiration - time())->toBeBetween(8, 10);
        while (time() <= (int) $expiration) {
            usleep(20000);
        }
        expect(app(AnalyticsCapabilities::class)->billingSummary($context, BillingSummaryCommand::fromMonth('2024-02'))['currencies'])->toHaveCount(8);

        return true;
    }, expectKilledWorker: true);
    expect($results)->toBe(['killed', true]);
});

test('PostgreSQL billing sparse and dense plan respects index row sentinel and two second budget', function () {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $query = app(BillingSummaryQuery::class);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    billingPgBulk($f, 1000000, '2023-01-10');
    billingPgBulk($f, 100, '2024-02-10', 1000000);
    DB::statement('ANALYZE fiscal_documents');
    $statement = $query->statement($context, $command);
    $plans = [];
    foreach (['sparse', 'dense'] as $mode) {
        if ($mode === 'dense') {
            billingPgBulk($f, 99900, '2024-02-15', 1000100);
            DB::statement('ANALYZE fiscal_documents');
        }
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['bindings'], useReadPdo: false);
        $decoded = json_decode($plan[0]->{'QUERY PLAN'}, true);
        $plans[$mode] = $decoded;
        file_put_contents(sys_get_temp_dir().'/phase4d-query-plans.json', json_encode($plans, JSON_PRETTY_PRINT));
        billingAssertPlan($decoded, $mode === 'dense' ? 100000 : 100);
        expect(json_encode($decoded))->not->toContain('external merge')->and($decoded[0]['Execution Time'])->toBeLessThan(2000);
        $data = $query->read($context, $command);
        expect(array_sum(array_column($data['currencies'], 'billed_document_count')))->toBe($mode === 'dense' ? 100000 : 100);
    }
    file_put_contents(sys_get_temp_dir().'/phase4d-query-plans.json', json_encode($plans, JSON_PRETTY_PRINT));
    billingPgBulk($f, 1, '2024-02-20', 1200000);
    expect(fn () => $query->read($context, $command))->toThrow(HttpException::class);
});

/** @return list<array<string,mixed>> */
function billingPlanNodes(array $node): array
{
    $nodes = [$node];
    foreach ($node['Plans'] ?? [] as $child) {
        array_push($nodes, ...billingPlanNodes($child));
    }

    return $nodes;
}

/** Test-only extraction of the actual candidate relation; the reference is independently specified. */
function billingAssertCandidateSequence(array $statement, int $count): void
{
    $position = strpos($statement['sql'], '), checked AS');
    expect($position)->not->toBeFalse();
    $actualSql = substr($statement['sql'], 0, $position).') SELECT id, document_date FROM candidates ORDER BY document_date, id';
    $keys = DB::cursor($actualSql, array_slice($statement['bindings'], 0, 10), useReadPdo: false);
    $expected = DB::cursor('SELECT id, document_date FROM fiscal_documents
        WHERE workspace_id = ? AND legal_entity_id = ? AND environment = ?
            AND document_date >= ? AND document_date < ? ORDER BY document_date, id LIMIT 100001',
        array_slice($statement['bindings'], 0, 5), useReadPdo: false);
    $seen = 0;
    $previous = null;
    $equal = true;
    foreach ($keys as $key) {
        $equal = $equal && $expected->valid() && $key == $expected->current()
            && ($previous === null || [$key->document_date, (int) $key->id] > $previous);
        $previous = [$key->document_date, (int) $key->id];
        $expected->next();
        $seen++;
    }
    expect($equal)->toBeTrue()->and($expected->valid())->toBeFalse()->and($seen)->toBe(min($count, 100001));
}

function billingPgPlanMetadata(): array
{
    return ['settings' => DB::select("SELECT name, setting FROM pg_settings WHERE name IN
        ('work_mem','hash_mem_multiplier','random_page_cost','seq_page_cost','max_parallel_workers_per_gather','server_version')"),
        'relation' => DB::select("SELECT reltuples, relpages, relallvisible FROM pg_class WHERE oid = 'fiscal_documents'::regclass"),
        'statistics' => DB::select("SELECT attname, n_distinct, correlation, most_common_vals, most_common_freqs, histogram_bounds
            FROM pg_stats WHERE tablename = 'fiscal_documents' AND attname IN ('id','workspace_id','legal_entity_id','environment','status','document_date')"),
        'indexes' => DB::select("SELECT indexname, indexdef, pg_relation_size(indexname::regclass) AS bytes FROM pg_indexes
            WHERE schemaname = current_schema() AND tablename = 'fiscal_documents'")];
}

function billingAssertPlan(array $plan, int $selected): void
{
    $nodes = billingPlanNodes($plan[0]['Plan']);
    expect(array_values(array_filter($nodes, fn ($node) => ($node['Relation Name'] ?? '') === 'fiscal_documents')))->toHaveCount(3);
    foreach ($nodes as $node) {
        expect($node['Temp Read Blocks'] ?? 0)->toBe(0)
            ->and($node['Temp Written Blocks'] ?? 0)->toBe(0)
            ->and($node['Disk Usage'] ?? 0)->toBe(0)
            ->and($node['HashAgg Batches'] ?? 1)->toBeLessThanOrEqual(1)
            ->and($node['Sort Space Type'] ?? 'Memory')->not->toBe('Disk');
        if (($node['Relation Name'] ?? '') === 'fiscal_documents') {
            expect($node['Node Type'])->toBeIn(['Index Scan', 'Index Only Scan']);
        }
        if ($node['Node Type'] === 'Aggregate') {
            expect($node['Actual Rows'])->toBeLessThanOrEqual(9);
        }
    }
    $keys = array_values(array_filter($nodes, fn ($n) => ($n['Relation Name'] ?? '') === 'fiscal_documents' && str_contains($n['Index Cond'] ?? '', 'document_date >=')));
    expect($keys)->toHaveCount(2);
    foreach ($keys as $range => $scan) {
        expect($scan['Node Type'])->toBeIn(['Index Scan', 'Index Only Scan'])
            ->and($scan['Scan Direction'])->toBe('Forward')->and((int) $scan['Actual Loops'])->toBe(1)
            ->and((int) $scan['Actual Rows'])->toBe($range === 0 ? min($selected, 50000) : max(0, $selected - 50000));
        $definition = DB::selectOne('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$scan['Index Name']])->indexdef;
        expect($definition)->toContain('USING btree (workspace_id, legal_entity_id, environment, document_date, id)');
        foreach (['workspace_id', 'legal_entity_id', 'environment', 'document_date >=', 'document_date <'] as $predicate) {
            expect($scan['Index Cond'])->toContain($predicate);
        }
        if ($range === 1) {
            expect($scan['Index Cond'])->toContain('ROW(document_date, id) > ROW(');
        }
    }
    $limits = array_values(array_filter($nodes, fn ($n) => $n['Node Type'] === 'Limit'));
    $candidate = array_values(array_filter($limits, fn ($n) => count(array_filter($n['Plans'], fn ($c) => $c['Node Type'] === 'Merge Append')) === 1));
    expect($candidate)->toHaveCount(1)->and((int) $candidate[0]['Actual Rows'])->toBe($selected)
        ->and((int) $candidate[0]['Actual Loops'])->toBe(1)->and($candidate[0]['Plan Width'])->toBe(12);
    $merge = array_values(array_filter($candidate[0]['Plans'], fn ($n) => $n['Node Type'] === 'Merge Append'))[0];
    expect($merge['Sort Key'])->toBe(['first_keys.document_date', 'first_keys.id']);
    $first = array_values(array_filter($limits, fn ($n) => ($n['Subplan Name'] ?? '') === 'CTE first_keys'));
    expect($first)->toHaveCount(1)->and((int) $first[0]['Actual Rows'])->toBe(min($selected, 50000))->and($first[0]['Plan Width'])->toBe(12);
    $boundary = array_values(array_filter($nodes, fn ($n) => $n['Node Type'] === 'Sort' && ($n['Sort Key'] ?? []) === ['first_keys_1.document_date DESC', 'first_keys_1.id DESC']));
    expect($boundary)->toHaveCount(1)->and($boundary[0]['Actual Rows'])->toBeLessThanOrEqual(1)
        ->and($boundary[0]['Sort Space Used'])->toBeLessThanOrEqual(64)
        ->and($boundary[0]['Plans'][0]['Node Type'])->toBe('CTE Scan')
        ->and($boundary[0]['Plans'][0]['Actual Rows'])->toBeLessThanOrEqual(50000);
    $payloads = array_values(array_filter($limits, fn ($n) => count(array_filter($n['Plans'], fn ($c) => ($c['Relation Name'] ?? '') === 'fiscal_documents' && ! str_contains($c['Index Cond'] ?? '', 'document_date'))) === 1));
    expect($payloads)->toHaveCount(1);
    $payload = $payloads[0];
    expect($payload['Actual Rows'])->toBeLessThanOrEqual(1)->and((int) $payload['Actual Loops'])->toBe($selected);
    $point = $payload['Plans'][0];
    expect($point['Node Type'])->toBeIn(['Index Scan', 'Index Only Scan']);
    $predicates = ($point['Index Cond'] ?? '').($point['Filter'] ?? '');
    foreach (['id = first_keys.id', 'workspace_id', 'legal_entity_id', 'environment'] as $predicate) {
        expect($predicates)->toContain($predicate);
    }
    expect($predicates)->not->toContain('document_date')->and($plan[0]['Execution Time'])->toBeLessThan(2000);
}

test('PostgreSQL billing independent volume plans preserve bounded scoped work without spill', function (int $count) {
    $f = billingSummaryFixture();
    $context = billingPgContext($f);
    $query = app(BillingSummaryQuery::class);
    $command = BillingSummaryCommand::fromMonth('2024-02');
    billingPgBulk($f, 1000000, '2023-01-10');
    $first = min($count, 100);
    billingPgBulk($f, $first, '2024-02-10', 1000000);
    if ($count > $first) {
        billingPgBulk($f, $count - $first, '2024-02-15', 1000000 + $first);
    }
    $foreign = billingSummaryFixture();
    billingPgBulk($foreign, 1000, '2024-02-10', 2000000);
    $sibling = $f;
    $sibling['entity'] = LegalEntity::factory()->configured()->create(['workspace_id' => $f['entity']->workspace_id]);
    billingPgBulk($sibling, 1000, '2024-02-10', 2100000);
    $homologation = billingSummaryFixture(environment: 'homologation');
    billingPgBulk($homologation, 1000, '2024-02-10', 2200000);
    $boundHomologation = $f;
    $boundHomologation['integration'] = clone $f['integration'];
    $boundHomologation['integration']->environment = 'homologation';
    billingPgBulk($boundHomologation, 1000, '2024-02-10', 2300000);
    DB::statement('ANALYZE fiscal_documents');
    $statement = $query->statement($context, $command);
    expect($statement['sql'])->toContain('SELECT id, document_date', 'ORDER BY document_date, id LIMIT 100001', 'LEFT JOIN LATERAL',
        'first_keys AS MATERIALIZED', 'LIMIT 50000', 'LIMIT 50001', '(document_date, id) > (SELECT document_date, id FROM boundary)')
        ->not->toContain('checked AS MATERIALIZED', 'candidates AS MATERIALIZED');
    $before = DB::selectOne("SELECT current_setting('work_mem') AS memory, current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock");
    expect($before->memory)->toBe('4MB');
    $observations = [];
    foreach (range(0, 3) as $refresh) {
        if ($refresh > 0) {
            DB::statement('ANALYZE fiscal_documents');
        }
        $metadata = billingPgPlanMetadata();
        $plan = DB::transaction(function () use ($statement) {
            DB::statement('SET TRANSACTION READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '2000ms'");
            DB::statement("SET LOCAL lock_timeout = '250ms'");

            return json_decode(DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['bindings'], useReadPdo: false)[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
        });
        $observations[] = compact('refresh', 'metadata', 'plan');
        file_put_contents(sys_get_temp_dir().'/phase4d-round2-runtime-plan-'.$count.'.json', json_encode(['count' => $count, 'sql' => $statement, 'settings' => $before, 'observations' => $observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        billingAssertPlan($plan, min($count, 100001));
    }
    billingAssertCandidateSequence($statement, $count);
    if ($count <= 100000) {
        $data = $query->read($context, $command);
        expect($data['currencies'])->toHaveCount(8)
            ->and(array_sum(array_column($data['currencies'], 'billed_document_count')))->toBe($count)
            ->and(array_sum(array_map('intval', array_column($data['currencies'], 'invoiced_gross_minor'))))->toBe($count);
        $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertOk()->assertJsonPath('data.currencies', $data['currencies']);
        $head = $this->withToken($f['secret'])->json('HEAD', $f['url'].'?month=2024-02')->assertOk();
        expect($head->getContent())->toBe('');
    } else {
        $this->withToken($f['secret'])->getJson($f['url'].'?month=2024-02')->assertServiceUnavailable()->assertJsonMissingPath('data');
        $head = $this->withToken($f['secret'])->json('HEAD', $f['url'].'?month=2024-02')->assertServiceUnavailable();
        expect($head->getContent())->toBe('');
        expect(DB::table('activity_log')->where('event', 'analytics.billing.read')->count())->toBe(0)->and($f['credential']->fresh()->last_used_at)->toBeNull();
        $lock = Cache::store('database')->lock('billing-query-workspace:'.$f['entity']->workspace_id, 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
    }
    $after = DB::selectOne("SELECT current_setting('work_mem') AS memory, current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock");
    expect($after)->toEqual($before)->and(DB::connection()->transactionLevel())->toBe(0);
})->with([0, 100, 100000, 100001, 200001]);
