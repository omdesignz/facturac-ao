<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiInvocationRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Deterministic route selection. A workspace with tenant AI settings never falls back to another route. */
final readonly class RoutedAssistantAiGateway implements VapAiGateway
{
    public function __construct(private LegacyAssistantAiGateway $legacy, private AssistantProviderTransport $transport) {}

    public function infer(AiInferenceRequest $request): AiInferenceResult
    {
        $identity = AiModelIdentity::LegacyAnthropicIntent;
        $invocation = $request->invocation();
        try {
            $settings = DB::table('tenant_ai_settings')->useWritePdo()->where('workspace_id', $invocation->context->execution->workspaceId)->first();
        } catch (\Throwable) {
            return AiInferenceResult::failed($identity, AiGatewayFailure::Unavailable);
        }
        if ($settings === null) {
            return $this->legacy->infer($request);
        }
        try {
            $route = $this->route($settings);
            $gates = null;
            if ($route === AiCredentialRoute::VapManaged) {
                $gates = fn (\stdClass $current) => $this->vap($current);
                try {
                    $gates($settings);
                } catch (\Throwable) {
                    throw new TenantAiInvocationRefused(AiInvocationDenial::VapUnavailable);
                }
            }
            $ceiling = AiToolCeiling::admit($invocation, $settings, $gates);
            if ($ceiling === null) {
                throw new TenantAiInvocationRefused($route === AiCredentialRoute::VapManaged ? AiInvocationDenial::VapUnavailable : AiInvocationDenial::RouteDisabled);
            }
            $invocation->restrict($ceiling);
            if ($route === AiCredentialRoute::VapManaged) {
                return $this->legacy->infer($request);
            }

            return AiInferenceResult::proposedPlan($identity, (new TenantAiInvocationSession($this->transport))->run($invocation));
        } catch (\Throwable $error) {
            if ($error instanceof TenantAiInvocationRefused && ! $error->recorded) {
                try {
                    TenantAiInvocationAdmission::audit($invocation->context, ['deployment_id' => $settings->deployment_id,
                        'ownership_kind' => $settings->mode, 'connection_id' => $settings->connection_id,
                        'credential_version_id' => $settings->credential_version_id, 'profile_id' => $settings->profile_id,
                        'expected_settings_revision' => (int) $settings->revision], 'invocation_credential_denied', $error->reason->value);
                } catch (\Throwable) {
                    // The fixed failure below never depends on its own audit.
                }
            }
            $failure = match (true) {
                $error instanceof TenantAiInvocationRefused => $error->reason === AiInvocationDenial::QuotaExceeded ? AiGatewayFailure::QuotaExceeded : AiGatewayFailure::Unavailable,
                $error instanceof HttpExceptionInterface => AiGatewayFailure::tryFrom($error->getStatusCode()) ?? AiGatewayFailure::Unavailable,
                default => AiGatewayFailure::Unavailable,
            };

            return AiInferenceResult::failed($identity, $failure);
        }
    }

    private function route(\stdClass $settings): AiCredentialRoute
    {
        if ($settings->deployment_id !== config('tenant_ai.deployment_id')) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::RouteDisabled);
        }

        return match ($settings->mode) {
            'vap_managed' => AiCredentialRoute::VapManaged,
            'customer_managed' => $settings->credential_version_id === null
                ? throw new TenantAiInvocationRefused(AiInvocationDenial::CredentialPending) : AiCredentialRoute::CustomerManaged,
            default => throw new TenantAiInvocationRefused(AiInvocationDenial::AiDisabled),
        };
    }

    /** Control-plane gates of the VAP route; it never reads a customer connection or credential. */
    private function vap(\stdClass $settings): void
    {
        $now = DB::getDriverName() === 'pgsql' ? TenantAiVerificationAdmission::instant() : CarbonImmutable::now('UTC');
        $accepted = TenantAiCatalogue::profile();
        $profile = DB::table('ai_model_profiles')->useWritePdo()->where('id', $settings->profile_id)->first();
        abort_unless(config('assistant.enabled') === true && config('tenant_ai.inference.enabled') === true
            && $settings->mode === 'vap_managed' && $settings->deployment_id === config('tenant_ai.deployment_id')
            && $settings->profile_id === TenantAiCatalogue::ID && $profile !== null && AiToolCeiling::flag($profile->allows_vap)
            && $profile->profile_key === AssistantProviderProfile::ID && $profile->manifest_sha256 === $accepted['manifest_sha256']
            && ! $now->lt(CarbonImmutable::parse($profile->valid_from)) && $now->lt(CarbonImmutable::parse($profile->valid_until)), 503);
        $controls = DB::table('ai_gateway_controls')->useWritePdo()->where('deployment_id', $settings->deployment_id)->orderBy('id')->limit(8)->get()
            ->filter(fn (\stdClass $control): bool => ($control->kind === 'global' && $control->id === $settings->root_control_id)
                || ($control->kind === 'provider' && $control->subject_key === 'anthropic')
                || ($control->kind === 'model' && $control->profile_id === $settings->profile_id));
        abort_unless($controls->count() === 3, 503);
        foreach ($controls as $control) {
            abort_unless(AiToolCeiling::flag($control->enabled) && AiToolCeiling::cleared($control->circuit_blocked)
                && $control->approval_expires_at !== null && $now->lt(CarbonImmutable::parse($control->approval_expires_at)), 503);
        }
    }
}
