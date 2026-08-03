<?php

namespace App\Actions;

use App\AgtConnectionCheckStatus;
use App\AgtConnectionStatus;
use App\AgtOperation;
use App\Fiscal\Agt\AgtConnectionReadiness;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\LegalEntityStatus;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RunAgtConnectionCheck
{
    public function __construct(
        private AgtGateway $gateway,
        private AgtConnectionReadiness $readiness,
    ) {}

    public function execute(AgtConnection $connection, LegalEntity $legalEntity, User $user): AgtConnectionCheck
    {
        $result = Cache::lock("agt-connection-check:{$connection->id}", 30)
            ->block(3, function () use ($connection, $legalEntity, $user): AgtConnectionCheck {
                $operation = AgtOperation::ListSeriesProbe;
                $check = AgtConnectionCheck::query()->create([
                    'workspace_id' => $connection->workspace_id,
                    'legal_entity_id' => $connection->legal_entity_id,
                    'agt_connection_id' => $connection->id,
                    'operation' => $operation,
                    'status' => AgtConnectionCheckStatus::Running,
                    'probe_uuid' => (string) Str::uuid(),
                    'endpoint_path' => $operation->endpointPath(),
                    'safe_message' => 'A verificar a configuração local.',
                    'attempt_count' => 0,
                    'requested_by_user_id' => $user->id,
                    'started_at' => now(),
                ]);

                $establishment = $legalEntity->establishments()
                    ->where('is_active', true)
                    ->orderByDesc('is_head_office')
                    ->oldest('id')
                    ->first();
                $readiness = $this->readiness->evaluate(
                    $connection,
                    $legalEntity,
                    $establishment instanceof Establishment ? $establishment : null,
                    $user,
                );

                if (! $readiness['complete']) {
                    $missingKeys = [];

                    foreach ($readiness['items'] as $item) {
                        if (! $item['done']) {
                            $missingKeys[] = 'LOCAL_'.strtoupper($item['key']);
                        }
                    }

                    return $this->completeLocallyFailedCheck($check, $connection, $missingKeys, $user);
                }

                $probe = $this->gateway->probeListSeries($connection, $legalEntity);

                return DB::transaction(function () use ($check, $connection, $legalEntity, $probe, $user): AgtConnectionCheck {
                    $check->update([
                        'status' => $probe->successful
                            ? AgtConnectionCheckStatus::Passed
                            : AgtConnectionCheckStatus::Failed,
                        'endpoint_path' => $probe->endpoint,
                        'request_body_sha256' => $probe->requestBodySha256,
                        'response_body_sha256' => $probe->responseBodySha256,
                        'http_status' => $probe->httpStatus,
                        'result_code' => $probe->resultCode,
                        'error_codes' => $probe->errorCodes,
                        'safe_message' => $probe->safeMessage,
                        'duration_ms' => $probe->durationMs,
                        'attempt_count' => $probe->attemptCount,
                        'completed_at' => now(),
                    ]);

                    $connection->forceFill([
                        'status' => $probe->successful
                            ? AgtConnectionStatus::Verified
                            : AgtConnectionStatus::Failed,
                        'verified_at' => $probe->successful ? now() : null,
                        'last_failed_at' => $probe->successful ? null : now(),
                    ])->save();

                    if ($probe->successful && $legalEntity->status === LegalEntityStatus::Configured) {
                        $legalEntity->update(['status' => LegalEntityStatus::Homologation]);
                    }

                    $this->recordActivity($check, $user);

                    return $check->fresh() ?? $check;
                });
            });

        return $result;
    }

    /** @param list<string> $missingKeys */
    private function completeLocallyFailedCheck(
        AgtConnectionCheck $check,
        AgtConnection $connection,
        array $missingKeys,
        User $user,
    ): AgtConnectionCheck {
        return DB::transaction(function () use ($check, $connection, $missingKeys, $user): AgtConnectionCheck {
            $check->update([
                'status' => AgtConnectionCheckStatus::Failed,
                'error_codes' => $missingKeys,
                'safe_message' => 'Conclua os requisitos locais antes de contactar a AGT.',
                'duration_ms' => 0,
                'attempt_count' => 0,
                'completed_at' => now(),
            ]);
            $connection->forceFill([
                'status' => AgtConnectionStatus::Failed,
                'verified_at' => null,
                'last_failed_at' => now(),
            ])->save();
            $this->recordActivity($check, $user);

            return $check->fresh() ?? $check;
        });
    }

    private function recordActivity(AgtConnectionCheck $check, User $user): void
    {
        activity('agt-connection')
            ->causedBy($user)
            ->performedOn($check)
            ->event('agt-connection-checked')
            ->withProperties([
                'workspace_id' => $check->workspace_id,
                'legal_entity_id' => $check->legal_entity_id,
                'connection_id' => $check->agt_connection_id,
                'status' => $check->status->value,
                'http_status' => $check->http_status,
                'result_code' => $check->result_code,
                'error_codes' => $check->error_codes ?? [],
                'request_body_sha256' => $check->request_body_sha256,
                'response_body_sha256' => $check->response_body_sha256,
            ])
            ->log('Verificação da ligação AGT concluída.');
    }
}
