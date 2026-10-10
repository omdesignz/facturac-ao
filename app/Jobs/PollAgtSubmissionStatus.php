<?php

namespace App\Jobs;

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Documents\AgtSubmissionExecution;
use App\FiscalDocumentEventType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentEvent;
use App\Models\Workspace;
use App\Notifications\DocumentAcceptedByAgt;
use App\Notifications\DocumentRejectedByAgt;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PollAgtSubmissionStatus implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 8;

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
        return [30, 60, 120, 300];
    }

    /** @return list<RateLimited> */
    public function middleware(): array
    {
        return [(new RateLimited('agt'))->releaseAfter(10)];
    }

    public function uniqueId(): string
    {
        return "poll:{$this->submissionId}";
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

        if ($claim === null || $claim->request_id === null) {
            return;
        }

        $result = $gateway->queryInvoiceStatus(
            $claim->agtConnection,
            $claim->legalEntity,
            $claim->request_id,
        );
        $scheduleNextPoll = AgtSubmissionExecution::complete($claim, $result, function (AgtSubmission $submission, array $outcome) use ($result): bool {
            if ($outcome['reason'] === 'unknown_response' || $outcome['classification'] === 'conflicting') {
                $this->markFailed($submission, $result, [], 'O resultado requer revisão da evidência.');

                return false;
            }

            if (! $result->successful) {
                return $this->handleUnsuccessfulResult($submission, $result);
            }

            if ($outcome['reported_state'] === 'processing' || $outcome['reason'] === 'refresh_pending') {
                $wasProcessing = $submission->status === AgtSubmissionStatus::Processing;
                $submission->update([
                    'status' => AgtSubmissionStatus::Processing,
                    'last_response_body_sha256' => $result->responseBodySha256,
                    'last_http_status' => $result->httpStatus,
                    'last_result_code' => $result->resultCode,
                    'last_error_codes' => [],
                    'safe_message' => $result->safeMessage,
                    'next_attempt_at' => now()->addSeconds($this->pollInterval()),
                ]);

                if (! $wasProcessing) {
                    $this->recordEvent(
                        $submission,
                        FiscalDocumentEventType::Processing,
                        'N',
                        ['result_code' => $result->resultCode],
                    );
                }

                return true;
            }

            if ($outcome['reported_state'] === 'processing_cancelled') {
                $this->complete(
                    $submission,
                    AgtSubmissionStatus::Cancelled,
                    FiscalDocumentEventType::ProcessingCancelled,
                    'N',
                    $result,
                    $result->requestErrorCodes,
                );

                return false;
            }

            $documentErrorCodes = [];
            foreach ($result->documents as $documentStatus) {
                if ($documentStatus->documentNumber === $submission->fiscalDocument->document_no && $documentStatus->status === 'I') {
                    $documentErrorCodes = $documentStatus->errorCodes;
                    break;
                }
            }

            if ($outcome['reported_state'] === 'valid') {
                $this->complete(
                    $submission,
                    AgtSubmissionStatus::Valid,
                    FiscalDocumentEventType::Validated,
                    'V',
                    $result,
                    [],
                );

                return false;
            }

            if ($outcome['reported_state'] !== 'invalid') {
                $this->markFailed($submission, $result, [], 'O resultado requer revisão da evidência.');

                return false;
            }

            $this->complete(
                $submission,
                AgtSubmissionStatus::Invalid,
                FiscalDocumentEventType::Invalidated,
                'I',
                $result,
                $documentErrorCodes,
            );

            return false;
        });

        if ($scheduleNextPoll) {
            static::dispatch($claim->id)
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
        return AgtSubmissionExecution::claim($this->submissionId, AgtSubmissionAttemptOperation::QueryStatus, $this->executionId, $this->job?->attempts() ?? 1);
    }

    private function handleUnsuccessfulResult(
        AgtSubmission $submission,
        AgtInvoiceStatusResult $result,
    ): bool {
        if ($result->retryable) {
            $submission->update([
                'last_response_body_sha256' => $result->responseBodySha256,
                'last_http_status' => $result->httpStatus,
                'last_result_code' => $result->resultCode,
                'last_error_codes' => $result->requestErrorCodes,
                'safe_message' => $result->safeMessage,
                'next_attempt_at' => now()->addSeconds($this->pollInterval()),
            ]);

            return true;
        }

        $this->markFailed(
            $submission,
            $result,
            $result->requestErrorCodes,
            $result->safeMessage,
        );

        return false;
    }

    /** @param list<string> $errorCodes */
    private function complete(
        AgtSubmission $submission,
        AgtSubmissionStatus $status,
        FiscalDocumentEventType $eventType,
        string $agtStatus,
        AgtInvoiceStatusResult $result,
        array $errorCodes,
    ): void {
        $submission->update([
            'status' => $status,
            'last_response_body_sha256' => $result->responseBodySha256,
            'last_http_status' => $result->httpStatus,
            'last_result_code' => $result->resultCode,
            'last_error_codes' => $errorCodes,
            'safe_message' => $result->safeMessage,
            'next_attempt_at' => null,
            'completed_at' => now(),
            'failed_at' => $status === AgtSubmissionStatus::Invalid ? now() : null,
        ]);
        $this->recordEvent($submission, $eventType, $agtStatus, [
            'request_id' => $submission->request_id,
            'result_code' => $result->resultCode,
            'error_codes' => $errorCodes,
            'response_body_sha256' => $result->responseBodySha256,
        ]);

        DB::afterCommit(fn () => $this->announce($submission, $status, $errorCodes));
    }

    /**
     * Tells the company how the AGT answered.
     *
     * Only the two terminal outcomes are worth a notification: the polling in
     * between is the app's business, not the user's.
     *
     * @param  list<string>  $errorCodes
     */
    private function announce(
        AgtSubmission $submission,
        AgtSubmissionStatus $status,
        array $errorCodes,
    ): void {
        $workspace = $submission->workspace;
        $document = $submission->fiscalDocument;

        if (! $workspace instanceof Workspace || ! $document instanceof FiscalDocument) {
            return;
        }

        if ($status === AgtSubmissionStatus::Cancelled) {
            return;
        }

        $notification = $status === AgtSubmissionStatus::Valid
            ? DocumentAcceptedByAgt::fromDocument($document)
            : DocumentRejectedByAgt::fromDocument($document, $errorCodes);

        $notification->sendToWorkspace($workspace);
    }

    /** @param list<string> $errorCodes */
    private function markFailed(
        AgtSubmission $submission,
        AgtInvoiceStatusResult $result,
        array $errorCodes,
        string $message,
    ): void {
        $submission->update([
            'status' => AgtSubmissionStatus::Failed,
            'last_response_body_sha256' => $result->responseBodySha256,
            'last_http_status' => $result->httpStatus,
            'last_result_code' => $result->resultCode,
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
            [
                'result_code' => $result->resultCode,
                'error_codes' => $errorCodes,
                'response_body_sha256' => $result->responseBodySha256,
            ],
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

    private function pollInterval(): int
    {
        return max(10, (int) config('agt.transport.poll_interval_seconds', 30));
    }
}
