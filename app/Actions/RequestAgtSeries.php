<?php

namespace App\Actions;

use App\AgtConnectionCheckStatus;
use App\AgtConnectionStatus;
use App\AgtOperation;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtSeriesRequestResult;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RequestAgtSeries
{
    public function __construct(private AgtGateway $gateway) {}

    public function execute(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        FiscalDocumentType $documentType,
        int $seriesYear,
        User $user,
    ): AgtSeriesRequestResult {
        return Cache::lock(
            "agt-series-request:{$connection->id}:{$documentType->value}:{$seriesYear}",
            60,
        )->block(3, function () use ($connection, $legalEntity, $documentType, $seriesYear, $user): AgtSeriesRequestResult {
            $this->assertScope($connection, $legalEntity);
            $operation = AgtOperation::RequestSeries;
            $submissionUuid = (string) Str::uuid();
            $check = AgtConnectionCheck::query()->create([
                'workspace_id' => $connection->workspace_id,
                'legal_entity_id' => $connection->legal_entity_id,
                'agt_connection_id' => $connection->id,
                'operation' => $operation,
                'status' => AgtConnectionCheckStatus::Running,
                'probe_uuid' => $submissionUuid,
                'endpoint_path' => $operation->endpointPath(),
                'safe_message' => "A solicitar uma série {$documentType->value} para {$seriesYear}.",
                'attempt_count' => 0,
                'requested_by_user_id' => $user->id,
                'started_at' => now(),
            ]);
            $result = $this->gateway->requestSeries(
                $connection,
                $legalEntity,
                $documentType,
                $seriesYear,
                FiscalSeriesContingency::Normal,
                $submissionUuid,
            );

            return DB::transaction(function () use ($check, $result, $documentType, $seriesYear, $user): AgtSeriesRequestResult {
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
                $this->recordActivity($check, $documentType, $seriesYear, $result, $user);

                return $result;
            });
        });
    }

    private function assertScope(AgtConnection $connection, LegalEntity $legalEntity): void
    {
        abort_unless(
            $connection->workspace_id === $legalEntity->workspace_id
                && $connection->legal_entity_id === $legalEntity->id,
            404,
        );
        abort_unless($connection->status === AgtConnectionStatus::Verified, 409);
    }

    private function recordActivity(
        AgtConnectionCheck $check,
        FiscalDocumentType $documentType,
        int $seriesYear,
        AgtSeriesRequestResult $result,
        User $user,
    ): void {
        activity('agt-connection')
            ->causedBy($user)
            ->performedOn($check)
            ->event('agt-series-requested')
            ->withProperties([
                'workspace_id' => $check->workspace_id,
                'legal_entity_id' => $check->legal_entity_id,
                'connection_id' => $check->agt_connection_id,
                'document_type' => $documentType->value,
                'series_year' => $seriesYear,
                'series_code' => $result->seriesCode,
                'authorized_quantity' => $result->authorizedQuantity,
                'status' => $check->status->value,
                'http_status' => $check->http_status,
                'result_code' => $check->result_code,
                'error_codes' => $check->error_codes ?? [],
                'request_body_sha256' => $check->request_body_sha256,
                'response_body_sha256' => $check->response_body_sha256,
            ])
            ->log('Pedido de série fiscal AGT concluído.');
    }
}
