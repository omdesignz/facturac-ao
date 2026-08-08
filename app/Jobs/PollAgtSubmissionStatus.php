<?php

namespace App\Jobs;

use App\AgtSubmissionAttemptOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtDocumentStatusResult;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\FiscalDocumentEventType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentEvent;
use App\Models\Workspace;
use App\Notifications\DocumentAcceptedByAgt;
use App\Notifications\DocumentRejectedByAgt;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Throwable;

class PollAgtSubmissionStatus implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 25;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $submissionId)
    {
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

        $startedAt = now();
        $result = $gateway->queryInvoiceStatus(
            $claim->agtConnection,
            $claim->legalEntity,
            $claim->request_id,
        );
        $scheduleNextPoll = DB::transaction(function () use ($claim, $result, $startedAt): bool {
            $submission = AgtSubmission::query()
                ->with('fiscalDocument')
                ->lockForUpdate()
                ->find($claim->id);

            if (! $submission instanceof AgtSubmission || $submission->status->isTerminal()) {
                return false;
            }

            $this->recordAttempt($submission, $result, $startedAt);

            if (! $result->successful) {
                return $this->handleUnsuccessfulResult($submission, $result);
            }

            if ($result->isProcessing()) {
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

            if ($result->isCancelled()) {
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

            $documentStatus = collect($result->documents)->first(
                fn (AgtDocumentStatusResult $document): bool => $document->documentNumber
                    === $submission->fiscalDocument->document_no,
            );

            if (! $documentStatus instanceof AgtDocumentStatusResult) {
                $this->markFailed(
                    $submission,
                    $result,
                    ['AGT_DOCUMENT_STATUS_MISSING'],
                    'A AGT concluiu o pedido sem devolver o estado deste documento.',
                );

                return false;
            }

            if ($documentStatus->status === 'V') {
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

            $this->complete(
                $submission,
                AgtSubmissionStatus::Invalid,
                FiscalDocumentEventType::Invalidated,
                'I',
                $result,
                $documentStatus->errorCodes,
            );

            return false;
        }, 3);

        if ($scheduleNextPoll) {
            static::dispatch($claim->id)
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

            $submission->update([
                'status' => AgtSubmissionStatus::Failed,
                'last_error_codes' => ['QUEUE_ATTEMPTS_EXHAUSTED'],
                'safe_message' => 'A consulta do estado excedeu o limite seguro de tentativas.',
                'next_attempt_at' => null,
                'completed_at' => now(),
                'failed_at' => now(),
            ]);
            $this->recordEvent(
                $submission,
                FiscalDocumentEventType::DeliveryFailed,
                'N',
                ['error_codes' => ['QUEUE_ATTEMPTS_EXHAUSTED']],
            );
        }, 3);
    }

    private function claim(): ?AgtSubmission
    {
        return DB::transaction(function (): ?AgtSubmission {
            $submission = AgtSubmission::query()
                ->with(['agtConnection', 'legalEntity', 'fiscalDocument'])
                ->lockForUpdate()
                ->find($this->submissionId);

            if (! $submission instanceof AgtSubmission || ! $submission->status->canPoll()) {
                return null;
            }

            if (blank($submission->request_id)) {
                $submission->update([
                    'status' => AgtSubmissionStatus::Failed,
                    'last_error_codes' => ['REQUEST_ID_MISSING'],
                    'safe_message' => 'Não existe identificador AGT para consultar este pedido.',
                    'next_attempt_at' => null,
                    'completed_at' => now(),
                    'failed_at' => now(),
                ]);

                return null;
            }

            $submission->update([
                'attempt_count' => $submission->attempt_count + 1,
                'next_attempt_at' => now()->addMinutes(2),
            ]);

            return $submission->fresh(['agtConnection', 'legalEntity', 'fiscalDocument']);
        }, 3);
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

        $this->announce($submission, $status, $errorCodes);
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

    private function recordAttempt(
        AgtSubmission $submission,
        AgtInvoiceStatusResult $result,
        CarbonInterface $startedAt,
    ): void {
        $submission->attempts()->create([
            'workspace_id' => $submission->workspace_id,
            'legal_entity_id' => $submission->legal_entity_id,
            'operation' => AgtSubmissionAttemptOperation::QueryStatus,
            'attempt_number' => $submission->attempt_count,
            'endpoint_path' => $result->endpoint,
            'request_body' => $result->requestBody,
            'request_body_sha256' => $result->requestBodySha256,
            'response_body' => $result->responseBody,
            'response_body_sha256' => $result->responseBodySha256,
            'http_status' => $result->httpStatus,
            'result_code' => $result->resultCode,
            'error_codes' => $result->requestErrorCodes,
            'safe_message' => $result->safeMessage,
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
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
