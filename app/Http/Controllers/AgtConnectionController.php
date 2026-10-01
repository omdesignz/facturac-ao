<?php

namespace App\Http\Controllers;

use App\Actions\SaveAgtConnection;
use App\AgtEnvironment;
use App\Fiscal\Agt\AgtConnectionReadiness;
use App\Fiscal\Documents\FiscalSeriesYearWindow;
use App\FiscalDocumentType;
use App\Http\Requests\UpdateAgtConnectionRequest;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AgtConnectionController extends Controller
{
    public function show(
        Request $request,
        AgtConnectionReadiness $readiness,
        FiscalSeriesYearWindow $seriesYearWindow,
    ): Response|RedirectResponse {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace->legalEntities()
            ->with(['establishments' => fn ($query) => $query->orderByDesc('is_head_office')->oldest('id')])
            ->oldest('id')
            ->first();

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('view', $legalEntity);

        $establishment = $legalEntity->establishments->firstWhere('is_head_office', true)
            ?? $legalEntity->establishments->first();
        $connection = $legalEntity->agtConnections()
            ->where('environment', AgtEnvironment::Homologation)
            ->first();
        $checks = $connection?->checks()
            ->latest('started_at')
            ->limit(20)
            ->get() ?? collect();
        $series = $connection?->fiscalSeries()
            ->orderByDesc('series_year')
            ->orderBy('document_type')
            ->orderBy('series_code')
            ->get() ?? collect();
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        Inertia::encryptHistory();

        return Inertia::render('Agt/Connection/Show', [
            'legalEntity' => [
                'legal_name' => $legalEntity->legal_name,
                'tax_identification_number' => $legalEntity->tax_identification_number,
                'establishment_name' => $establishment instanceof Establishment ? $establishment->name : null,
            ],
            'connection' => $this->connectionProps($connection),
            'readiness' => $readiness->evaluate(
                $connection,
                $legalEntity,
                $establishment instanceof Establishment ? $establishment : null,
                $user,
            ),
            'checks' => $checks
                ->map(fn (AgtConnectionCheck $check): array => $this->checkProps($check))
                ->values()
                ->all(),
            'series' => $series
                ->map(fn (FiscalSeries $fiscalSeries): array => [
                    'public_id' => $fiscalSeries->public_id,
                    'series_code' => $fiscalSeries->series_code,
                    'series_year' => $fiscalSeries->series_year,
                    'document_type' => $fiscalSeries->document_type->value,
                    'document_type_label' => $fiscalSeries->document_type->label(),
                    'status' => $fiscalSeries->status->value,
                    'status_label' => $fiscalSeries->status->label(),
                    'contingency' => $fiscalSeries->contingency_indicator->value,
                    'contingency_label' => $fiscalSeries->contingency_indicator->label(),
                    'invoicing_method' => $fiscalSeries->invoicing_method,
                    'next_number' => $fiscalSeries->next_number,
                    'last_authorized_number' => $fiscalSeries->last_authorized_number,
                    'remaining_numbers' => $fiscalSeries->remainingNumbers(),
                    'synchronized_at' => $fiscalSeries->synchronized_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'seriesRequest' => [
                'document_types' => collect(FiscalDocumentType::issuable())
                    ->map(fn (FiscalDocumentType $documentType): array => [
                        'value' => $documentType->value,
                        'label' => $documentType->label(),
                    ])
                    ->values()
                    ->all(),
                'years' => $seriesYearWindow->allowedYears(now('UTC')),
                'default_document_type' => FiscalDocumentType::Invoice->value,
            ],
            'permissions' => [
                'manage' => Gate::allows('manageAgtConnection', $legalEntity),
                'test' => Gate::allows('testAgtConnection', $legalEntity),
                'request_series' => Gate::allows('testAgtConnection', $legalEntity)
                    && $connection?->status->value === 'verified',
                'sync_series' => Gate::allows('testAgtConnection', $legalEntity)
                    && $connection?->status->value === 'verified',
            ],
            'guardrails' => [
                'production_enabled' => AgtEnvironment::Production->isEnabled(),
                'mfa_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'private_keys_in_database' => false,
            ],
        ]);
    }

    public function update(
        UpdateAgtConnectionRequest $request,
        SaveAgtConnection $saveAgtConnection,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $user = $request->user();

        abort_unless($legalEntity instanceof LegalEntity && $user instanceof User, 404);
        $saveAgtConnection->execute($legalEntity, $user, $request->connectionProfile());

        return redirect()
            ->route('agt.connection.show')
            ->with('success', 'Configuração de homologação guardada. Os segredos não serão novamente apresentados.');
    }

    /** @return array<string, mixed> */
    private function connectionProps(?AgtConnection $connection): array
    {
        if ($connection === null) {
            $environment = AgtEnvironment::Homologation;

            return [
                'public_id' => null,
                'environment' => $environment->value,
                'environment_label' => $environment->label(),
                'endpoint_host' => parse_url($environment->baseUrl(), PHP_URL_HOST),
                'schema_version' => (string) config('agt.schema_version', '1.2'),
                'status' => 'draft',
                'status_label' => 'Configuração incompleta',
                'has_basic_credentials' => false,
                'credential_identity' => null,
                'product_id' => '',
                'product_version' => '',
                'software_validation_number' => '',
                'establishment_number' => '',
                'software_key_reference' => '',
                'software_key_fingerprint' => null,
                'taxpayer_key_reference' => '',
                'taxpayer_key_fingerprint' => null,
                'configured_at' => null,
                'verified_at' => null,
            ];
        }

        $environment = $connection->environment;

        return [
            'public_id' => $connection->public_id,
            'environment' => $environment->value,
            'environment_label' => $environment->label(),
            'endpoint_host' => parse_url($environment->baseUrl(), PHP_URL_HOST),
            'schema_version' => $connection->schema_version,
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'has_basic_credentials' => $connection->hasBasicCredentials(),
            'credential_identity' => $this->maskIdentity($connection->basic_auth_username),
            'product_id' => $connection->product_id ?? '',
            'product_version' => $connection->product_version ?? '',
            'software_validation_number' => $connection->software_validation_number ?? '',
            'establishment_number' => $connection->establishment_number ?? '',
            'software_key_reference' => $connection->software_key_reference ?? '',
            'software_key_fingerprint' => $this->abbreviateFingerprint($connection->software_key_fingerprint),
            'taxpayer_key_reference' => $connection->taxpayer_key_reference ?? '',
            'taxpayer_key_fingerprint' => $this->abbreviateFingerprint($connection->taxpayer_key_fingerprint),
            'configured_at' => $connection->configured_at?->toIso8601String(),
            'verified_at' => $connection->verified_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function checkProps(AgtConnectionCheck $check): array
    {
        return [
            'public_id' => $check->public_id,
            'operation' => $check->operation->value,
            'operation_label' => $check->operation->label(),
            'status' => $check->status->value,
            'status_label' => $check->status->label(),
            'http_status' => $check->http_status,
            'result_code' => $check->result_code,
            'error_codes' => $check->error_codes ?? [],
            'safe_message' => $check->safe_message,
            'duration_ms' => $check->duration_ms,
            'attempt_count' => $check->attempt_count,
            'request_fingerprint' => $this->abbreviateFingerprint($check->request_body_sha256),
            'response_fingerprint' => $this->abbreviateFingerprint($check->response_body_sha256),
            'started_at' => $check->started_at->toIso8601String(),
            'completed_at' => $check->completed_at?->toIso8601String(),
        ];
    }

    private function maskIdentity(?string $identity): ?string
    {
        if (blank($identity)) {
            return null;
        }

        $identity = (string) $identity;

        return mb_strlen($identity) <= 4
            ? str_repeat('•', mb_strlen($identity))
            : mb_substr($identity, 0, 2).str_repeat('•', max(4, mb_strlen($identity) - 4)).mb_substr($identity, -2);
    }

    private function abbreviateFingerprint(?string $fingerprint): ?string
    {
        return filled($fingerprint)
            ? Str::lower(Str::substr((string) $fingerprint, 0, 12)).'…'.Str::lower(Str::substr((string) $fingerprint, -8))
            : null;
    }
}
