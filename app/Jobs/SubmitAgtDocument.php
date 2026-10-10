<?php

namespace App\Jobs;

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\FiscalDocumentEventType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocumentEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SubmitAgtDocument implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 25;

    public int $uniqueFor = 120;

    public readonly string $executionId;

    public function __construct(public readonly int $submissionId, ?string $executionId = null)
    {
        $this->executionId = $executionId ?? (string) Str::uuid();
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

        $result = $gateway->registerInvoice($claim->agtConnection, $claim->request_body);
        $shouldPoll = AgtSubmissionExecution::complete($claim, $result, function (AgtSubmission $submission, array $outcome) use ($result): ?int {
            if ($outcome['classification'] === 'conflicting' || $outcome['reason'] === 'unknown_response'
                || ! hash_equals($submission->request_body_sha256, $result->requestBodySha256)) {
                $this->markFailed(
                    $submission,
                    'Os bytes enviados não correspondem ao registo fiscal congelado.',
                    ['REQUEST_BYTES_MISMATCH'],
                );

                return null;
            }

            if ($result->accepted && $result->requestId !== null && ($outcome['delivery_state'] ?? null) === 'acknowledged') {
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
                DB::afterCommit(fn () => $this->release($delay));

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
        });

        if ($shouldPoll !== null) {
            PollAgtSubmissionStatus::dispatch($shouldPoll)
                ->delay(now()->addSeconds($this->pollInterval()))
                ->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        AgtSubmissionExecution::fail($this->submissionId, $this->executionId, $this->job?->attempts() ?? 1);
    }

    private function claim(): ?AgtSubmission
    {
        return AgtSubmissionExecution::claim($this->submissionId, AgtSubmissionAttemptOperation::RegisterInvoice, $this->executionId, $this->job?->attempts() ?? 1);
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
