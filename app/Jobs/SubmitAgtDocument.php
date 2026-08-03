<?php

namespace App\Jobs;

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\FiscalDocumentEventType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocumentEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubmitAgtDocument implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 25;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $submissionId)
    {
        $this->onQueue('agt');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    /** @return list<RateLimited> */
    public function middleware(): array
    {
        return [(new RateLimited('agt'))->releaseAfter(10)];
    }

    public function uniqueId(): string
    {
        return "submit:{$this->submissionId}";
    }

    public function rateLimitKey(): string
    {
        return 'connection:'.(string) (AgtSubmission::query()
            ->whereKey($this->submissionId)
            ->value('agt_connection_id') ?? 'missing');
    }

    public function handle(AgtGateway $gateway): void
    {
        $claim = $this->claim();

        if ($claim === null) {
            return;
        }

        $startedAt = now();
        $result = $gateway->registerInvoice($claim->agtConnection, $claim->request_body);
        $shouldPoll = DB::transaction(function () use ($claim, $result, $startedAt): ?int {
            $submission = AgtSubmission::query()->lockForUpdate()->find($claim->id);

            if (! $submission instanceof AgtSubmission || $submission->status->isTerminal()) {
                return null;
            }

            $this->recordAttempt($submission, $result, $startedAt);

            if (! hash_equals($submission->request_body_sha256, $result->requestBodySha256)) {
                $this->markFailed(
                    $submission,
                    'Os bytes enviados não correspondem ao registo fiscal congelado.',
                    ['REQUEST_BYTES_MISMATCH'],
                );

                return null;
            }

            if ($result->accepted && $result->requestId !== null) {
                $submission->update([
                    'status' => AgtSubmissionStatus::Received,
                    'request_id' => $result->requestId,
                    'last_response_body_sha256' => $result->responseBodySha256,
                    'last_http_status' => $result->httpStatus,
                    'last_result_code' => null,
                    'last_error_codes' => [],
                    'safe_message' => $result->safeMessage,
                    'next_attempt_at' => now()->addSeconds($this->pollInterval()),
                    'received_at' => now(),
                    'failed_at' => null,
                ]);
                $this->recordEvent(
                    $submission,
                    FiscalDocumentEventType::SubmissionAccepted,
                    'N',
                    [
                        'request_id' => $result->requestId,
                        'http_status' => $result->httpStatus,
                        'response_body_sha256' => $result->responseBodySha256,
                    ],
                );

                return $submission->id;
            }

            if ($result->retryable && $submission->attempt_count < $this->tries) {
                $delay = $this->retryDelay($submission->attempt_count);
                $submission->update([
                    'status' => AgtSubmissionStatus::Retrying,
                    'last_response_body_sha256' => $result->responseBodySha256,
                    'last_http_status' => $result->httpStatus,
                    'last_result_code' => null,
                    'last_error_codes' => $result->errorCodes,
                    'safe_message' => $result->safeMessage,
                    'next_attempt_at' => now()->addSeconds($delay),
                    'failed_at' => null,
                ]);
                $this->release($delay);

                return null;
            }

            $terminalStatus = $result->retryable
                ? AgtSubmissionStatus::Failed
                : AgtSubmissionStatus::Rejected;
            $submission->update([
                'status' => $terminalStatus,
                'last_response_body_sha256' => $result->responseBodySha256,
                'last_http_status' => $result->httpStatus,
                'last_result_code' => null,
                'last_error_codes' => $result->errorCodes,
                'safe_message' => $result->safeMessage,
                'next_attempt_at' => null,
                'completed_at' => now(),
                'failed_at' => now(),
            ]);
            $this->recordEvent(
                $submission,
                $terminalStatus === AgtSubmissionStatus::Rejected
                    ? FiscalDocumentEventType::SubmissionRejected
                    : FiscalDocumentEventType::DeliveryFailed,
                'N',
                [
                    'http_status' => $result->httpStatus,
                    'error_codes' => $result->errorCodes,
                    'response_body_sha256' => $result->responseBodySha256,
                ],
            );

            return null;
        }, 3);

        if ($shouldPoll !== null) {
            PollAgtSubmissionStatus::dispatch($shouldPoll)
                ->delay(now()->addSeconds($this->pollInterval()))
                ->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $submission = AgtSubmission::query()->lockForUpdate()->find($this->submissionId);

            if (! $submission instanceof AgtSubmission || $submission->status->isTerminal()) {
                return;
            }

            $this->markFailed(
                $submission,
                'A entrega excedeu o limite seguro de tentativas e requer intervenção.',
                ['QUEUE_ATTEMPTS_EXHAUSTED'],
            );
        }, 3);
    }

    private function claim(): ?AgtSubmission
    {
        return DB::transaction(function (): ?AgtSubmission {
            $submission = AgtSubmission::query()
                ->with(['agtConnection', 'fiscalDocument'])
                ->lockForUpdate()
                ->find($this->submissionId);

            if (! $submission instanceof AgtSubmission) {
                return null;
            }

            $isStaleClaim = $submission->status === AgtSubmissionStatus::Sending
                && $submission->updated_at?->lt(now()->subMinutes(2));

            if (! $submission->status->canSubmit() && ! $isStaleClaim) {
                return null;
            }

            $submission->update([
                'status' => AgtSubmissionStatus::Sending,
                'safe_message' => 'A transmitir o documento para a AGT.',
                'attempt_count' => $submission->attempt_count + 1,
                'next_attempt_at' => now()->addMinutes(2),
                'submitted_at' => $submission->submitted_at ?? now(),
            ]);

            return $submission->fresh(['agtConnection', 'fiscalDocument']);
        }, 3);
    }

    private function recordAttempt(
        AgtSubmission $submission,
        AgtRegistrationResult $result,
        CarbonInterface $startedAt,
    ): void {
        $submission->attempts()->create([
            'workspace_id' => $submission->workspace_id,
            'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::RegisterInvoice,
            'attempt_number' => $submission->attempt_count,
            'endpoint_path' => $result->endpoint,
            'request_body' => $submission->request_body,
            'request_body_sha256' => $result->requestBodySha256,
            'response_body' => $result->responseBody,
            'response_body_sha256' => $result->responseBodySha256,
            'http_status' => $result->httpStatus,
            'result_code' => null,
            'error_codes' => $result->errorCodes,
            'safe_message' => $result->safeMessage,
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
    }

    /** @param list<string> $errorCodes */
    private function markFailed(
        AgtSubmission $submission,
        string $message,
        array $errorCodes,
    ): void {
        $submission->update([
            'status' => AgtSubmissionStatus::Failed,
            'last_error_codes' => $errorCodes,
            'safe_message' => $message,
            'next_attempt_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ]);
        $this->recordEvent(
            $submission,
            FiscalDocumentEventType::DeliveryFailed,
            'N',
            ['error_codes' => $errorCodes],
        );
    }

    /** @param array<string, mixed> $context */
    private function recordEvent(
        AgtSubmission $submission,
        FiscalDocumentEventType $eventType,
        string $agtStatus,
        array $context,
    ): FiscalDocumentEvent {
        return FiscalDocumentEvent::query()->create([
            'workspace_id' => $submission->workspace_id,
            'legal_entity_id' => $submission->legal_entity_id,
            'fiscal_document_id' => $submission->fiscal_document_id,
            'agt_submission_id' => $submission->id,
            'event_type' => $eventType,
            'agt_document_status' => $agtStatus,
            'safe_context' => $context,
            'occurred_at' => now(),
        ]);
    }

    private function retryDelay(int $attempt): int
    {
        return match ($attempt) {
            1 => 10,
            2 => 30,
            3 => 120,
            default => 300,
        };
    }

    private function pollInterval(): int
    {
        return max(10, (int) config('agt.transport.poll_interval_seconds', 30));
    }
}
