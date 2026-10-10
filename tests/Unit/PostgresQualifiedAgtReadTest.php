<?php

use App\Actions\IssueFiscalDocument;
use App\AgtEnvironment;
use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\DocumentCapabilities;
use App\Fiscal\DocumentReadCommand;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\ExecutionContext;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationReadContext;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory isolated PostgreSQL qualified-read gate.');
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName())->toBe('pgsql');
    expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    config(['integrations.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

function qualifiedPgWait(string $file): void
{
    $deadline = microtime(true) + 20;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Qualified read barrier timed out');
        }
        usleep(1000);
    }
}

/** Independent processes share only barrier files; each reconnects to the primary. */
function qualifiedPgPair(Closure $operation): array
{
    $directory = sys_get_temp_dir().'/facturac-qualified-read-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
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
                file_put_contents("$directory/ready-$worker", 'ready');
                qualifiedPgWait("$directory/start");
                try {
                    $result = ['ok' => true, 'value' => $operation($worker, $directory)];
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
                }
                file_put_contents("$directory/result-$worker", json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ([0, 1] as $worker) {
            qualifiedPgWait("$directory/ready-$worker");
        }
        file_put_contents("$directory/start", 'start');
        foreach ([0, 1] as $worker) {
            qualifiedPgWait("$directory/result-$worker");
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $result = array_map(fn (int $worker) => json_decode(file_get_contents("$directory/result-$worker"), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        Assert::assertSame([true, true], array_column($result, 'ok'), json_encode($result, JSON_THROW_ON_ERROR));

        return array_column($result, 'value');
    } finally {
        foreach ($pids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob("$directory/*") as $file) {
            unlink($file);
        } rmdir($directory);
        DB::purge();
    }
}

/** Existing gate fixtures/utilities are shared with the fiscal/integration/observation group. */
test('PostgreSQL qualified statement snapshot stays coherent across evidence writers', function (string $writer, bool $readFirst) {
    $f = pgIntegrationFixture(AgtEnvironment::Homologation, ['documents:agt-status:read']);
    $entity = LegalEntity::findOrFail($f['integration']->legal_entity_id);
    $document = FiscalDocument::factory()->issued()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $submission = AgtSubmission::factory()->received()->create(['fiscal_document_id' => $document->id, 'next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    if ($writer !== 'poll') {
        pgCompleteAcceptance($claim);
    }
    if ($writer === 'rebuild') {
        DB::table('agt_submissions')->where('id', $submission->id)->update(['qualified_projection' => null]);
    }
    $before = app(DocumentCapabilities::class)->readQualifiedAgtStatus(IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()), DocumentReadCommand::fromPublicId($document->public_id));
    $results = qualifiedPgPair(function (int $worker, string $directory) use ($f, $claim, $submission, $document, $writer, $readFirst) {
        if ($worker === 0) {
            $paused = false;
            $pause = function (string $sql) use (&$paused, $directory): void {
                if (! $paused && str_contains($sql, 'fiscal_documents')) {
                    $paused = true;
                    file_put_contents("$directory/read-boundary", 'ready');
                    qualifiedPgWait("$directory/written");
                }
            };
            if ($readFirst) {
                DB::listen(fn (QueryExecuted $q) => $pause($q->sql));
            } else {
                DB::connection()->beforeExecuting(fn (string $sql) => $pause($sql));
            }

            return app(DocumentCapabilities::class)->readQualifiedAgtStatus(IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()), DocumentReadCommand::fromPublicId($document->public_id));
        }
        qualifiedPgWait("$directory/read-boundary");
        match ($writer) {
            'poll' => pgCompleteAcceptance($claim),
            'conflict' => AgtSubmissionExecution::complete($claim, pgObservationResult($claim, 'I'), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Invalid])),
            'rebuild' => AgtReconstruction::rebuild($submission->id),
            'refresh' => DB::transaction(function () use ($submission): void {
                $locked = AgtSubmissionExecution::locked($submission->id);
                $now = AgtSubmissionExecution::databaseNow();
                $entry = AgtSubmissionExecution::append($locked, (string) Str::uuid(), $locked->operation_sequence + 1, 'claim', [
                    'version' => 1, 'operation' => 'query_status', 'execution_uuid' => (string) Str::uuid(), 'queue_attempt' => 1,
                    'request_identity_sha256' => hash('sha256', $locked->request_id), 'frozen_request_sha256' => $locked->request_body_sha256], $now, null);
                $locked->update(['operation_sequence' => $entry->operation_sequence]);
                AgtSubmissionExecution::project($locked, $entry);
            }),
        };
        file_put_contents("$directory/written", 'committed');

        return 'committed';
    });
    $after = app(DocumentCapabilities::class)->readQualifiedAgtStatus(IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid()), DocumentReadCommand::fromPublicId($document->public_id));
    foreach (['knowledge', 'reported_state', 'synchronization', 'observed_at', 'last_successful_sync_at', 'provenance', 'explanation_code', 'reconciliation_required'] as $key) {
        expect($results[0][$key])->toBe(($readFirst ? $before : $after)[$key]);
    }
    expect($after['knowledge'])->toBe(match ($writer) {
        'poll','rebuild' => 'known','conflict' => 'conflicting_evidence',default => 'unknown'
    });
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with(['poll', 'conflict', 'refresh', 'rebuild'])->with([true, false]);

test('PostgreSQL qualified snapshot never combines draft status with post-issuance submission', function (bool $readFirst) {
    $company = pgFiscalCompany();
    $company['user']->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $document = pgDraft($company);
    DB::table('fiscal_documents')->where('id', $document->id)->update(['environment' => 'homologation']);
    $context = ExecutionContext::resolve($company['user'], $company['entity'], AgtEnvironment::Homologation, readOnly: true);
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
    $results = qualifiedPgPair(function (int $worker, string $directory) use ($context, $company, $document, $readFirst) {
        if ($worker === 0) {
            $paused = false;
            $pause = function (string $sql) use (&$paused, $directory): void {
                if (! $paused && str_contains($sql, 'fiscal_documents')) {
                    $paused = true;
                    file_put_contents("$directory/read-boundary", 'ready');
                    qualifiedPgWait("$directory/written");
                }
            };
            if ($readFirst) {
                DB::listen(fn (QueryExecuted $q) => $pause($q->sql));
            } else {
                DB::connection()->beforeExecuting(fn (string $sql) => $pause($sql));
            }

            return app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($document->public_id));
        }
        qualifiedPgWait("$directory/read-boundary");
        app(IssueFiscalDocument::class)->execute($document, $company['user'], $company['series']->public_id, 1);
        file_put_contents("$directory/written", 'committed');

        return 'committed';
    });
    expect($results[0]['knowledge'])->toBe($readFirst ? 'not_applicable' : 'unknown')->and($results[0]['provenance'])->toBe($readFirst ? 'not_applicable' : 'workflow_only')
        ->and($document->fresh()->isMutable())->toBeFalse()->and(AgtSubmission::count())->toBe(1);
})->with([true, false]);

test('PostgreSQL qualified capability sees committed withdrawals and allows only already-authorized in-flight read', function (string $change, bool $alreadyAuthorized) {
    $f = pgIntegrationFixture(AgtEnvironment::Homologation, ['documents:agt-status:read']);
    $entity = LegalEntity::findOrFail($f['integration']->legal_entity_id);
    $document = FiscalDocument::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $context = IntegrationReadContext::authenticate($f['secret'], (string) Str::uuid());
    $results = qualifiedPgPair(function (int $worker, string $directory) use ($f, $context, $document, $change, $alreadyAuthorized) {
        if ($worker === 0) {
            if ($alreadyAuthorized) {
                $paused = false;
                DB::connection()->beforeExecuting(function (string $sql) use (&$paused, $directory): void {
                    if (! $paused && str_contains($sql, 'fiscal_documents')) {
                        $paused = true;
                        file_put_contents("$directory/authorized", 'ready');
                        qualifiedPgWait("$directory/withdrawn");
                    }
                });
            } else {
                file_put_contents("$directory/authorized", 'ready');
                qualifiedPgWait("$directory/withdrawn");
            }
            try {
                $data = app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($document->public_id));
                $first = 200;
            } catch (HttpException $e) {
                $first = $e->getStatusCode();
            }
            try {
                app(DocumentCapabilities::class)->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($document->public_id));
                throw new RuntimeException('Captured authority reused');
            } catch (HttpException $e) {
                return ['first' => $first, 'second' => $e->getStatusCode()];
            }
        }
        qualifiedPgWait("$directory/authorized");
        match ($change) {
            'revocation' => IntegrationCredential::where('integration_id', $f['integration']->id)->update(['revoked_at' => now()]),
            'scope' => DB::table('integration_scopes')->where('integration_id', $f['integration']->id)->delete(),
            'sponsor' => WorkspaceMembership::where('id', $f['integration']->sponsor_membership_id)->update(['role' => 'viewer']),
        };
        file_put_contents("$directory/withdrawn", 'committed');

        return 'committed';
    });
    $denied = $change === 'revocation' ? 401 : 403;
    expect($results[0])->toBe(['first' => $alreadyAuthorized ? 200 : $denied, 'second' => $denied]);
})->with(['revocation', 'scope', 'sponsor'])->with([false, true]);

test('PostgreSQL qualified GET HEAD overlapping rotation and V1 resources share actual fixed-window quota', function (bool $crossBoundary) {
    $scopes = ['documents:read', 'customers:read', 'catalogue:read', 'documents:agt-status:read'];
    $f = pgIntegrationFixture(AgtEnvironment::Production, $scopes);
    $entity = LegalEntity::findOrFail($f['integration']->legal_entity_id);
    $document = FiscalDocument::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'production']);
    $rotated = app(IntegrationCredentials::class)->rotate($f['context'], $f['issued']->integrationPublicId, 1, $f['issued']->credentialPublicId, $scopes);
    $secrets = [$f['secret'], $rotated->revealOnce()];
    // Native time, including a deliberate two-window burst; Carbon is not frozen.
    if ($crossBoundary) {
        while (time() % 60 < 51 || time() % 60 > 52) {
            usleep(50000);
        }
    }
    $results = qualifiedPgPair(function (int $worker, string $directory) use ($entity, $document, $secrets, $crossBoundary) {
        $entries = [];
        $p = ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => 'production', 'documentPublicId' => $document->public_id];
        $names = ['integrations.v1.documents.show', 'integrations.v1.customers.index', 'integrations.v1.catalogue.index', 'integrations.v2.documents.agt-status.show'];
        $initialWindow = intdiv(time(), 60);
        for ($i = 0; $i < ($crossBoundary ? 60 : 40); $i++) {
            if ($crossBoundary && $i === 30) {
                while (intdiv(time(), 60) === $initialWindow) {
                    usleep(10000);
                }
            }
            $url = strtok(route($names[$i % 4], $p), '?');
            $method = ($i % 2 === 0) ? 'GET' : 'HEAD';
            $request = Request::create($url, $method);
            $request->headers->set('Authorization', 'Bearer '.$secrets[$worker]);
            $start = intdiv(time(), 60);
            $response = app(Kernel::class)->handle($request);
            $end = intdiv(time(), 60);
            if ($method === 'HEAD' && $response->getContent() !== '') {
                throw new RuntimeException('HEAD disclosure');
            }
            $entries[] = ['start' => $start, 'end' => $end, 'status' => $response->getStatusCode()];
        }

        return $entries;
    });
    $entries = array_merge(...$results);
    $stable = array_filter($entries, fn (array $e) => $e['start'] === $e['end']);
    expect(count($stable))->toBeGreaterThanOrEqual($crossBoundary ? 118 : 78);
    if ($crossBoundary) {
        expect(count(array_filter($entries, fn (array $entry): bool => $entry['status'] === 200)))->toBe(120);
        expect(count(array_unique(array_column($entries, 'start'))))->toBe(2);
    }
    foreach (array_unique(array_column($stable, 'start')) as $window) {
        $counts = array_count_values(array_column(array_filter($stable, fn (array $e) => $e['start'] === $window), 'status'));
        expect(array_diff(array_keys($counts), [200, 429]))->toBe([])->and($counts[200] ?? 0)->toBeLessThanOrEqual(60);
    }
    if (count(array_unique(array_column($entries, 'start'))) === 1) {
        expect(count(array_filter($entries, fn (array $e) => $e['status'] === 200)))->toBe(60);
    }
    foreach (IntegrationCredential::where('integration_id', $f['integration']->id)->get() as $credential) {
        expect($credential->last_used_at)->not->toBeNull();
    }
    $before = IntegrationCredential::where('integration_id', $f['integration']->id)->pluck('last_used_at', 'id')->all();
    qualifiedPgPair(function (int $worker) use ($secrets) {
        $context = IntegrationReadContext::authenticate($secrets[$worker], (string) Str::uuid());
        $context->recordSuccessfulUse();

        return 'used';
    });
    foreach (IntegrationCredential::where('integration_id', $f['integration']->id)->get() as $credential) {
        expect($credential->last_used_at->gte($before[$credential->id]))->toBeTrue();
    }
    expect(Activity::where('event', 'documents.agt-status.read')->count())->toBeGreaterThan(0);
})->with([false, true]);
