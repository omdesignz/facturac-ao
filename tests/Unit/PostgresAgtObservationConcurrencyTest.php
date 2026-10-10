<?php

use App\Actions\IssueFiscalDocument;
use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Exceptions\ReceiptEvidenceUnavailable;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Data\AgtDocumentStatusResult;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Fiscal\Documents\AgtReconstruction;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\Fiscal\Documents\CurrentAgtState;
use App\Http\Controllers\AgtSubmissionRefreshController;
use App\Http\Requests\RefreshAgtSubmissionsRequest;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionObservation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('postgresql');

beforeEach(function () {
    if (getenv('FISCAL_PG_GATE') !== '1') {
        $this->markTestSkipped('Mandatory isolated PostgreSQL concurrency gate.');
    }
    expect(app()->environment())->toBe('testing');
    expect(DB::getDriverName())->toBe('pgsql');
    expect(str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_'))->toBeTrue();
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    Http::preventStrayRequests();
});

/** @param callable(int): mixed $operation
 * @return list<array<string, mixed>>
 */
function agtPgContend(Closure $operation, int $workers = 3): array
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

function pgObservationResult(AgtSubmission $submission, string $status = 'V'): AgtInvoiceStatusResult
{
    $request = json_encode(['requestID' => $submission->request_id, 'schemaVersion' => '2.0', 'taxRegistrationNumber' => $submission->legalEntity->tax_identification_number], JSON_THROW_ON_ERROR);
    $code = $status === 'I' ? '2' : '0';
    $response = json_encode(['resultCode' => $code, 'requestErrorList' => [], 'documentStatusList' => [['documentNo' => $submission->fiscalDocument->document_no, 'documentStatus' => $status, 'errorList' => []]]], JSON_THROW_ON_ERROR);

    return new AgtInvoiceStatusResult(true, false, '/obterEstado', 200, $request, hash('sha256', $request), $response, hash('sha256', $response), $code, [], [new AgtDocumentStatusResult($submission->fiscalDocument->document_no, $status, [])], 'POISON SECRET remote message', 1);
}

function pgCompleteAcceptance(AgtSubmission $claim): void
{
    AgtSubmissionExecution::complete($claim, pgObservationResult($claim), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]));
}

test('PostgreSQL concurrent polls acquire exactly one durable claim', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $results = agtPgContend(fn () => AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus)?->active_operation_uuid);
    expect(array_column($results, 'ok'))->toBe([true, true, true]);
    expect(count(array_filter(array_column($results, 'value'))))->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('kind', 'claim')->count())->toBe(1)
        ->and($submission->fresh()->operation_sequence)->toBe(1)
        ->and($submission->fresh()->projection_revision)->toBe(1);
    pgCompleteAcceptance($submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']));
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
});

test('PostgreSQL identical completions and concurrent rebuild converge to one committed result', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $results = agtPgContend(function (int $worker) use ($claim) {
        if ($worker === 2) {
            AgtReconstruction::rebuild($claim->id);
        } else {
            pgCompleteAcceptance($claim);
        }

        return true;
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and($submission->attempts()->count())->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('kind', 'result')->count())->toBe(1)
        ->and($submission->fresh()->projection_revision)->toBe(2)
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
});

test('PostgreSQL late opposite evidence cannot regain authority in either arrival order', function (bool $olderFirst) {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $older = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subMinute(), 'next_attempt_at' => null]);
    $newer = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $late = fn () => AgtSubmissionExecution::complete($older, pgObservationResult($older, 'I'), fn () => throw new RuntimeException('Stale generation applied'));
    if ($olderFirst) {
        $late();
        pgCompleteAcceptance($newer);
    } else {
        pgCompleteAcceptance($newer);
        $late();
    }
    expect($submission->fresh()->status)->toBe(AgtSubmissionStatus::Valid)
        ->and($submission->fresh()->qualified_projection['classification'])->toBe('conflicting')
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse()
        ->and($submission->attempts()->count())->toBe(2);
})->with([true, false]);

test('PostgreSQL concurrent differing completions retain conflict and deny receipt authority', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $results = agtPgContend(function (int $worker) use ($claim) {
        $status = $worker === 0 ? 'I' : 'V';
        AgtSubmissionExecution::complete($claim, pgObservationResult($claim, $status), fn (AgtSubmission $locked) => $locked->update(['status' => $status === 'V' ? AgtSubmissionStatus::Valid : AgtSubmissionStatus::Invalid, 'next_attempt_at' => null]));

        return true;
    });
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and($submission->attempts()->count())->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('kind', 'conflict')->count())->toBe(1)
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
});

test('PostgreSQL killed claimed worker recovers by lease and stale callback is fenced', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $results = agtPgContend(fn () => AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus, '11111111-1111-4111-8111-111111111111')?->active_operation_uuid, 1);
    expect($results[0]['ok'])->toBeTrue();
    $old = $submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']);
    $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subMinute(), 'next_attempt_at' => null]);
    $newer = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    pgCompleteAcceptance($newer);
    AgtSubmissionExecution::fail($submission->id, '11111111-1111-4111-8111-111111111111', 1);
    pgCompleteAcceptance($old);
    expect($submission->fresh()->status)->toBe(AgtSubmissionStatus::Valid)
        ->and($submission->fresh()->operation_sequence)->toBe(2)
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
});

test('PostgreSQL result failure rolls back evidence projection and audit together', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    expect(fn () => AgtSubmissionExecution::complete($claim, pgObservationResult($claim), fn () => throw new RuntimeException('rollback')))->toThrow(RuntimeException::class);
    expect($submission->attempts()->count())->toBe(0)
        ->and($submission->fresh()->projection_revision)->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('kind', 'result')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('log_name', 'agt_projection')->count())->toBe(1);
    pgCompleteAcceptance($claim);
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
});

test('PostgreSQL registration retry and poll preserve the acknowledged request identity', function () {
    $submission = AgtSubmission::factory()->received()->create(['status' => AgtSubmissionStatus::Retrying, 'request_id' => null, 'next_attempt_at' => null]);
    $results = agtPgContend(fn (int $worker) => AgtSubmissionExecution::claim($submission->id,
        $worker === 1 ? AgtSubmissionAttemptOperation::QueryStatus : AgtSubmissionAttemptOperation::RegisterInvoice)?->active_operation_uuid);
    expect(array_column($results, 'ok'))->toBe([true, true, true])
        ->and($results[1]['value'])->toBeNull()
        ->and(count(array_filter(array_column($results, 'value'))))->toBe(1);
    $older = $submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']);
    $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subMinute(), 'next_attempt_at' => null]);
    $newer = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::RegisterInvoice);
    $registration = function (string $id) use ($submission) {
        $response = json_encode(['requestID' => $id], JSON_THROW_ON_ERROR);

        return new AgtRegistrationResult(true, false, '/registarFactura', 200, $submission->request_body_sha256,
            $response, hash('sha256', $response), $id, [], 'POISON SECRET', 1);
    };
    AgtSubmissionExecution::complete($newer, $registration('123456789012345'), fn (AgtSubmission $locked) => $locked->update(['request_id' => '123456789012345', 'status' => AgtSubmissionStatus::Received, 'next_attempt_at' => null]));
    $poll = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    pgCompleteAcceptance($poll);
    AgtSubmissionExecution::complete($older, $registration('987654321098765'), fn () => throw new RuntimeException('Late registration changed request binding'));
    expect($submission->fresh()->request_id)->toBe('123456789012345')
        ->and($submission->fresh()->status)->toBe(AgtSubmissionStatus::Valid)
        ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue()
        ->and($submission->attempts()->count())->toBe(3);
});

test('PostgreSQL worker termination at each persistence boundary leaves recoverable or committed evidence', function (string $stage) {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    DB::purge();
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);
    if ($pid === 0) {
        DB::purge();
        $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
        if ($stage === 'after_claim') {
            posix_kill(getmypid(), SIGKILL);
        }
        $result = pgObservationResult($claim);
        if ($stage === 'after_response') {
            posix_kill(getmypid(), SIGKILL);
        }
        AgtSubmissionExecution::complete($claim, $result, function (AgtSubmission $locked) use ($stage): void {
            $locked->update(['status' => AgtSubmissionStatus::Valid, 'next_attempt_at' => null]);
            if ($stage === 'before_commit') {
                posix_kill(getmypid(), SIGKILL);
            }
        });
        posix_kill(getmypid(), SIGKILL);
        exit(3);
    }
    $deadline = microtime(true) + 15;
    while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
        if (microtime(true) > $deadline) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            throw new RuntimeException('Worker termination test timed out');
        }
        usleep(1000);
    }
    expect(pcntl_wtermsig($status))->toBe(SIGKILL);
    DB::purge();
    if ($stage === 'after_commit') {
        expect($submission->attempts()->count())->toBe(1)
            ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
        $claim = $submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']);
        $claim->active_operation_uuid = AgtSubmissionObservation::query()->where('kind', 'claim')->value('operation_uuid');
        pgCompleteAcceptance($claim);
        expect($submission->attempts()->count())->toBe(1);
    } else {
        expect($submission->attempts()->count())->toBe(0)
            ->and(AgtSubmissionObservation::query()->where('kind', 'result')->count())->toBe(0)
            ->and(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
        $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subMinute(), 'next_attempt_at' => null]);
        $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
        pgCompleteAcceptance($claim);
        expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeTrue();
    }
})->with(['after_claim', 'after_response', 'before_commit', 'after_commit']);

test('PostgreSQL receipt and conflicting evidence honor either serialization order', function (bool $receiptFirst) {
    Queue::fake();
    Notification::fake();
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
    $company = pgFiscalCompany();
    $source = pgDraft($company);
    app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, 1);
    recordAuthoritativeAgtAcceptance($source->refresh());
    $receipt = pgReceipt($company, $source, 11400);
    $series = pgReceiptSeries($company);
    $submission = $source->submissions()->firstOrFail();
    $claim = $submission->load(['legalEntity', 'fiscalDocument', 'agtConnection']);
    $claim->active_operation_uuid = AgtSubmissionObservation::query()->where('agt_submission_id', $submission->id)->where('kind', 'result')->latest('id')->value('operation_uuid');
    $barrier = sys_get_temp_dir().'/agt-receipt-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    try {
        $results = agtPgContend(function (int $worker) use ($claim, $receipt, $series, $company, $barrier, $receiptFirst) {
            $wait = function (string $file) use ($barrier): void {
                $deadline = microtime(true) + 10;
                while (! file_exists($barrier.'/'.$file)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Receipt barrier timed out');
                    }
                    usleep(1000);
                }
            };
            if ($worker === 0 && $receiptFirst) {
                $signalled = false;
                DB::listen(function (QueryExecuted $query) use (&$signalled, $barrier, $wait): void {
                    if (! $signalled && str_contains($query->sql, '"fiscal_documents"') && str_contains($query->sql, '"id" in') && str_contains($query->sql, 'for update')) {
                        $signalled = true;
                        file_put_contents($barrier.'/locked', '1');
                        $wait('receipt_started');
                    }
                });

                return app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $series->public_id, 1)->id;
            }
            if ($worker === 0) {
                return DB::transaction(function () use ($claim, $barrier, $wait) {
                    AgtSubmissionExecution::locked($claim->id);
                    file_put_contents($barrier.'/locked', '1');
                    $wait('receipt_started');
                    AgtSubmissionExecution::complete($claim, pgObservationResult($claim, 'I'), fn () => throw new RuntimeException('Duplicate must not apply'));

                    return true;
                });
            }
            $wait('locked');
            file_put_contents($barrier.'/receipt_started', '1');
            if ($receiptFirst) {
                AgtSubmissionExecution::complete($claim, pgObservationResult($claim, 'I'), fn () => throw new RuntimeException('Duplicate must not apply'));

                return true;
            }

            return app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $series->public_id, 1)->id;
        }, 2);
        expect($results[0]['ok'])->toBeTrue()
            ->and($results[1]['ok'])->toBe($receiptFirst)
            ->and($series->fresh()->next_number)->toBe($receiptFirst ? 2 : 1)
            ->and($receipt->fresh()->document_no !== null)->toBe($receiptFirst)
            ->and($receipt->fresh()->document_jws !== null)->toBe($receiptFirst)
            ->and(CurrentAgtState::acceptanceEvidenceSatisfied($source))->toBeFalse();
        if (! $receiptFirst) {
            expect($results[1]['error'])->toBe(ReceiptEvidenceUnavailable::class)
                ->and(DB::table('activity_log')->where('event', 'receipt-evidence-denied')->count())->toBe(1);
        }
    } finally {
        foreach (glob($barrier.'/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
})->with([false, true]);

test('PostgreSQL receipt versus poll or rebuild follows the committed evidence order', function (string $writer, bool $receiptFirst) {
    Queue::fake();
    Notification::fake();
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
    $company = pgFiscalCompany();
    $source = pgDraft($company);
    $submission = app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, 1);
    $source->refresh();
    $submission->update(['status' => $writer === 'poll' ? AgtSubmissionStatus::Received : AgtSubmissionStatus::Valid, 'request_id' => '123456789012345', 'next_attempt_at' => null]);
    if ($writer === 'poll') {
        $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    } else {
        $claim = $submission->load(['legalEntity', 'fiscalDocument', 'agtConnection']);
        $result = pgObservationResult($claim);
        $submission->attempts()->create(['workspace_id' => $submission->workspace_id, 'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::QueryStatus, 'attempt_number' => 1, 'endpoint_path' => $result->endpoint,
            'request_body' => $result->requestBody, 'request_body_sha256' => $result->requestBodySha256, 'response_body' => $result->responseBody,
            'response_body_sha256' => $result->responseBodySha256, 'http_status' => 200, 'result_code' => '0', 'error_codes' => [],
            'safe_message' => 'POISON SECRET', 'started_at' => now(), 'completed_at' => now()]);
        $submission->update(['attempt_count' => 1]);
    }
    $receipt = pgReceipt($company, $source, 11400);
    $series = pgReceiptSeries($company);
    $barrier = sys_get_temp_dir().'/agt-order-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    try {
        $results = agtPgContend(function (int $worker) use ($writer, $receiptFirst, $claim, $receipt, $series, $company, $barrier) {
            $wait = function (string $file) use ($barrier): void {
                $deadline = microtime(true) + 10;
                while (! file_exists($barrier.'/'.$file)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Ordering barrier timed out');
                    }
                    usleep(1000);
                }
            };
            $issue = fn () => app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $series->public_id, 1)->id;
            $observe = function () use ($writer, $claim): bool {
                if ($writer === 'poll') {
                    pgCompleteAcceptance($claim);
                } else {
                    AgtReconstruction::rebuild($claim->id);
                }

                return true;
            };
            $isReceipt = ($worker === 0) === $receiptFirst;
            if ($worker === 0) {
                if ($isReceipt) {
                    $signalled = false;
                    DB::listen(function (QueryExecuted $query) use (&$signalled, $barrier, $wait): void {
                        if (! $signalled && str_contains($query->sql, '"fiscal_documents"') && str_contains($query->sql, '"id" in') && str_contains($query->sql, 'for update')) {
                            $signalled = true;
                            file_put_contents($barrier.'/locked', '1');
                            $wait('second_started');
                        }
                    });

                    return $issue();
                }

                return DB::transaction(function () use ($claim, $observe, $barrier, $wait) {
                    AgtSubmissionExecution::locked($claim->id);
                    file_put_contents($barrier.'/locked', '1');
                    $wait('second_started');

                    return $observe();
                });
            }
            $wait('locked');
            file_put_contents($barrier.'/second_started', '1');

            return $isReceipt ? $issue() : $observe();
        }, 2);
        $receiptResult = $results[$receiptFirst ? 0 : 1];
        expect($results[$receiptFirst ? 1 : 0]['ok'])->toBeTrue()
            ->and($receiptResult['ok'])->toBe(! $receiptFirst)
            ->and(CurrentAgtState::validated($source))->toBeTrue()
            ->and($series->fresh()->next_number)->toBe($receiptFirst ? 1 : 2)
            ->and($receipt->fresh()->document_no !== null)->toBe(! $receiptFirst);
        if ($receiptFirst) {
            expect($receiptResult['error'])->toBe(ReceiptEvidenceUnavailable::class);
        }
    } finally {
        foreach (glob($barrier.'/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
})->with([['poll', false], ['poll', true], ['rebuild', false], ['rebuild', true]]);

test('PostgreSQL refresh racing receipt cannot shorten an active lease or authorize uncertain evidence', function () {
    Queue::fake();
    Notification::fake();
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
    $company = pgFiscalCompany();
    $this->actingAs($company['user']);
    $source = pgDraft($company);
    $submission = app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, 1);
    $source->refresh();
    $submission->update(['status' => AgtSubmissionStatus::Received, 'request_id' => '123456789012345', 'next_attempt_at' => null]);
    $claim = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $receipt = pgReceipt($company, $source, 11400);
    $series = pgReceiptSeries($company);
    $results = agtPgContend(function (int $worker) use ($company, $receipt, $series) {
        if ($worker === 1) {
            return app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $series->public_id, 1)->id;
        }
        $request = RefreshAgtSubmissionsRequest::create('/agt/submissions/refresh', 'POST');
        $request->setUserResolver(fn () => $company['user']);
        $request->attributes->set('currentWorkspace', $company['entity']->workspace);
        if (! $request->authorize()) {
            throw new RuntimeException('Fixture has no refresh authority');
        }

        return app(AgtSubmissionRefreshController::class)($request)->getStatusCode();
    });
    expect($results[0]['ok'])->toBeTrue()->and($results[2]['ok'])->toBeTrue()
        ->and($results[1]['error'])->toBe(ReceiptEvidenceUnavailable::class)
        ->and($submission->fresh()->active_operation_uuid)->toBe($claim->active_operation_uuid)
        ->and($submission->fresh()->operation_lease_expires_at->getTimestamp())->toBe($claim->operation_lease_expires_at->getTimestamp())
        ->and($submission->fresh()->next_attempt_at->getTimestamp())->toBe($claim->next_attempt_at->getTimestamp())
        ->and($series->fresh()->next_number)->toBe(1)
        ->and(AgtSubmissionObservation::query()->where('agt_submission_id', $submission->id)->count())->toBe(1);
});

test('PostgreSQL scoped projection and replay plans are measured on a populated journal', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $statementCount = 0;
    DB::listen(function () use (&$statementCount): void {
        $statementCount++;
    });
    $lockStarted = microtime(true);
    DB::transaction(function () use ($submission): void {
        $locked = AgtSubmissionExecution::locked($submission->id);
        $outcome = ['version' => 1, 'classification' => 'unknown', 'knowledge' => 'legacy_unverified', 'reported_state' => null,
            'sync' => 'idle', 'delivery_state' => 'unknown', 'reason' => 'legacy_unverified', 'successful_sync' => false, 'eligible_generation' => true];
        for ($sequence = 1; $sequence <= 300; $sequence++) {
            AgtSubmissionExecution::append($locked, (string) Str::uuid(), $sequence, 'legacy_import', $outcome, AgtSubmissionExecution::databaseNow(), null);
        }
        $entry = AgtSubmissionObservation::query()->where('agt_submission_id', $locked->id)->latest('id')->firstOrFail();
        $locked->update(['operation_sequence' => 300]);
        AgtSubmissionExecution::project($locked, $entry);
    });
    $measurement = ['journal_rows' => 300, 'statements' => $statementCount, 'locked_transaction_ms' => round((microtime(true) - $lockStarted) * 1000, 3)];
    DB::statement('ANALYZE agt_submission_observations');
    DB::statement('ANALYZE agt_submissions');
    $queries = ['replay' => ['SELECT id, operation_sequence, outcome FROM agt_submission_observations WHERE agt_submission_id = ? ORDER BY operation_sequence,id', [$submission->id], 300],
        'scoped_projection' => ['SELECT qualified_projection FROM agt_submissions WHERE workspace_id = ? AND legal_entity_id = ? AND environment = ? AND fiscal_document_id = ?', [$submission->workspace_id, $submission->legal_entity_id, $submission->environment, $submission->fiscal_document_id], 1]];
    $plans = ['fixture_measurement' => $measurement];
    foreach ($queries as $name => [$sql, $bindings, $count]) {
        $plan = json_decode(DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        expect((int) $plan['Plan']['Actual Rows'])->toBe($count);
        $plans[$name] = $plan;
    }
    file_put_contents(sys_get_temp_dir().'/facturac-phase4b-query-plans.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect(CurrentAgtState::validated($submission->fiscalDocument))->toBeFalse();
});

test('PostgreSQL old V waits for a newer unknown commit and cannot restore authority', function () {
    $submission = AgtSubmission::factory()->received()->create(['next_attempt_at' => null]);
    $older = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $submission->refresh()->update(['operation_lease_expires_at' => AgtSubmissionExecution::databaseNow()->subSecond(), 'next_attempt_at' => null]);
    $newer = AgtSubmissionExecution::claim($submission->id, AgtSubmissionAttemptOperation::QueryStatus);
    $barrier = sys_get_temp_dir().'/agt-unknown-order-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    try {
        $results = agtPgContend(function (int $worker) use ($older, $newer, $barrier) {
            $wait = function (string $file) use ($barrier): void {
                $deadline = microtime(true) + 10;
                while (! file_exists($barrier.'/'.$file)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Unknown-order barrier timed out');
                    }
                    usleep(1000);
                }
            };
            if ($worker === 0) {
                return DB::transaction(function () use ($newer, $barrier, $wait) {
                    AgtSubmissionExecution::locked($newer->id);
                    file_put_contents($barrier.'/locked', '1');
                    $wait('older_started');
                    AgtSubmissionExecution::complete($newer, pgObservationResult($newer, 'UNSUPPORTED'), fn (AgtSubmission $locked) => $locked->update(['status' => AgtSubmissionStatus::Failed, 'next_attempt_at' => null]));

                    return true;
                });
            }
            $wait('locked');
            file_put_contents($barrier.'/older_started', '1');
            AgtSubmissionExecution::complete($older, pgObservationResult($older), fn () => throw new RuntimeException('Old authority applied'));

            return true;
        }, 2);
        expect(array_column($results, 'ok'))->toBe([true, true])
            ->and($submission->fresh()->qualified_projection['reported_state'])->toBeNull()
            ->and($submission->fresh()->status)->toBe(AgtSubmissionStatus::Failed)
            ->and(CurrentAgtState::acceptanceEvidenceSatisfied($submission->fiscalDocument))->toBeFalse()
            ->and($submission->attempts()->count())->toBe(2);
        $before = $submission->fresh()->qualified_projection;
        AgtReconstruction::rebuild($submission->id);
        expect($submission->fresh()->qualified_projection)->toBe($before);
    } finally {
        foreach (glob($barrier.'/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
});
