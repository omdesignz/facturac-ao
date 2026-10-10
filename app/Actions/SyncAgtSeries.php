<?php

namespace App\Actions;

use App\AgtConnectionCheckStatus;
use App\AgtConnectionStatus;
use App\AgtOperation;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtSeriesData;
use App\Fiscal\Documents\FiscalSeriesRange;
use App\FiscalSeriesStatus;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class SyncAgtSeries
{
    public function __construct(
        private AgtGateway $gateway,
        private FiscalSeriesRange $seriesRange,
    ) {}

    /** @return array{successful: bool, synchronized: int, message: string} */
    public function execute(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        Establishment $establishment,
        User $user,
    ): array {
        return Cache::lock("agt-series-sync:{$connection->id}", 60)
            ->block(3, function () use ($connection, $legalEntity, $establishment, $user): array {
                $this->assertScope($connection, $legalEntity, $establishment);
                $operation = AgtOperation::SyncSeries;
                $startedAt = now();
                $check = AgtConnectionCheck::query()->create([
                    'workspace_id' => $connection->workspace_id,
                    'legal_entity_id' => $connection->legal_entity_id,
                    'agt_connection_id' => $connection->id,
                    'operation' => $operation,
                    'status' => AgtConnectionCheckStatus::Running,
                    'probe_uuid' => (string) Str::uuid(),
                    'endpoint_path' => $operation->endpointPath(),
                    'safe_message' => 'A obter as séries fiscais autorizadas pela AGT.',
                    'attempt_count' => 0,
                    'requested_by_user_id' => $user->id,
                    'started_at' => $startedAt,
                ]);
                $result = $this->gateway->listSeries($connection, $legalEntity);

                if ($result->successful) {
                    try {
                        foreach ($result->series as $remoteSeries) {
                            $this->validateRemoteSeries($remoteSeries);
                        }
                    } catch (UnexpectedValueException $exception) {
                        $check->update([
                            'status' => AgtConnectionCheckStatus::Failed,
                            'endpoint_path' => $result->endpoint,
                            'request_body_sha256' => $result->requestBodySha256,
                            'response_body_sha256' => $result->responseBodySha256,
                            'http_status' => $result->httpStatus,
                            'result_code' => $result->resultCode,
                            'error_codes' => ['AGT_SERIES_RANGE_INVALID'],
                            'safe_message' => $exception->getMessage(),
                            'duration_ms' => $result->durationMs,
                            'attempt_count' => $result->attemptCount,
                            'completed_at' => now(),
                        ]);
                        $this->recordActivity($check, $user, 0);

                        return [
                            'successful' => false,
                            'synchronized' => 0,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }

                return DB::transaction(function () use (
                    $check,
                    $connection,
                    $legalEntity,
                    $establishment,
                    $user,
                    $result,
                ): array {
                    $check->update([
                        'status' => $result->successful
                            ? AgtConnectionCheckStatus::Passed
                            : AgtConnectionCheckStatus::Failed,
                        'endpoint_path' => $result->endpoint,
                        'request_body_sha256' => $result->requestBodySha256,
                        'response_body_sha256' => $result->responseBodySha256,
                        'http_status' => $result->httpStatus,
                        'result_code' => $result->resultCode,
                        'error_codes' => $result->errorCodes,
                        'safe_message' => $result->safeMessage,
                        'duration_ms' => $result->durationMs,
                        'attempt_count' => $result->attemptCount,
                        'completed_at' => now(),
                    ]);

                    if (! $result->successful) {
                        $this->recordActivity($check, $user, 0);

                        return [
                            'successful' => false,
                            'synchronized' => 0,
                            'message' => $result->safeMessage,
                        ];
                    }

                    $synchronized = 0;

                    foreach ($result->series as $remoteSeries) {
                        $this->storeSeries(
                            $connection,
                            $legalEntity,
                            $establishment,
                            $remoteSeries,
                        );
                        $synchronized++;
                    }

                    $this->recordActivity($check, $user, $synchronized);

                    return [
                        'successful' => true,
                        'synchronized' => $synchronized,
                        'message' => "{$synchronized} série(s) fiscal(is) sincronizada(s) com a AGT.",
                    ];
                }, 3);
            });
    }

    private function assertScope(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        Establishment $establishment,
    ): void {
        abort_unless(
            $connection->workspace_id === $legalEntity->workspace_id
                && $connection->legal_entity_id === $legalEntity->id
                && $establishment->workspace_id === $legalEntity->workspace_id
                && $establishment->legal_entity_id === $legalEntity->id,
            404,
        );
        abort_unless($connection->status === AgtConnectionStatus::Verified, 409);
    }

    private function storeSeries(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        Establishment $establishment,
        AgtSeriesData $remote,
    ): FiscalSeries {
        [$first, $last, $lastCreated] = $this->validateRemoteSeries($remote);

        $series = FiscalSeries::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('series_code', $remote->seriesCode)
            ->where('environment', $connection->environment->value)
            ->lockForUpdate()
            ->first();
        if ($series instanceof FiscalSeries && $series->agtConnection->environment !== $connection->environment) {
            throw new UnexpectedValueException('A série existente pertence a outro ambiente AGT. A sincronização foi recusada.');
        }
        $nextFromAgt = max($first, ($lastCreated ?? ($first - 1)) + 1);
        $next = $series instanceof FiscalSeries
            ? max($series->next_number, $nextFromAgt)
            : $nextFromAgt;
        $status = match (true) {
            $remote->status === FiscalSeriesStatus::Closed || $next > $last => FiscalSeriesStatus::Closed,
            $remote->status === FiscalSeriesStatus::InUse || $series?->last_issued_number !== null => FiscalSeriesStatus::InUse,
            default => FiscalSeriesStatus::Open,
        };

        $series ??= new FiscalSeries([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'establishment_id' => $establishment->id,
            'agt_connection_id' => $connection->id,
            'series_code' => $remote->seriesCode,
        ]);
        $series->fill([
            'series_year' => $remote->seriesYear,
            'document_type' => $remote->documentType,
            'status' => $status,
            'contingency_indicator' => $remote->contingency,
            'invoicing_method' => $remote->invoicingMethod,
            'agt_creation_date' => $remote->creationDate,
            'agt_first_document_no' => $remote->firstDocumentApproved,
            'agt_last_document_no' => $remote->lastDocumentApproved,
            'agt_first_document_created' => $remote->firstDocumentCreated,
            'agt_last_document_created' => $remote->lastDocumentCreated,
            'first_authorized_number' => $first,
            'last_authorized_number' => $last,
            'next_number' => $next,
            'synchronized_at' => now(),
        ])->save();

        return $series;
    }

    /** @return array{int, int, int|null} */
    private function validateRemoteSeries(AgtSeriesData $remote): array
    {
        $first = $this->seriesRange->sequence($remote->firstDocumentApproved);
        $last = $this->seriesRange->sequence($remote->lastDocumentApproved);
        $lastCreated = $this->seriesRange->optionalSequence($remote->lastDocumentCreated);

        if ($first > $last || ($lastCreated !== null && ($lastCreated < $first || $lastCreated > $last))) {
            throw new UnexpectedValueException('A AGT devolveu limites incompatíveis para uma série fiscal.');
        }

        return [$first, $last, $lastCreated];
    }

    private function recordActivity(AgtConnectionCheck $check, User $user, int $synchronized): void
    {
        activity('agt-connection')
            ->causedBy($user)
            ->performedOn($check)
            ->event('agt-series-synchronized')
            ->withProperties([
                'workspace_id' => $check->workspace_id,
                'legal_entity_id' => $check->legal_entity_id,
                'connection_id' => $check->agt_connection_id,
                'status' => $check->status->value,
                'series_count' => $synchronized,
                'http_status' => $check->http_status,
                'result_code' => $check->result_code,
                'error_codes' => $check->error_codes ?? [],
                'request_body_sha256' => $check->request_body_sha256,
                'response_body_sha256' => $check->response_body_sha256,
            ])
            ->log('Sincronização de séries fiscais AGT concluída.');
    }
}
