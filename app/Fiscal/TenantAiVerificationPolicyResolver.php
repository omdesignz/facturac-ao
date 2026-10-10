<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Only deployment-reviewed manifests are eligible; database profiles cannot approve themselves. */
final class TenantAiVerificationPolicyResolver
{
    public function resolve(TenantAiVerificationContext $context, TenantAiVerificationRequest $request, bool $lock): TenantAiVerificationPolicy
    {
        $owner = $context->authorize();
        $configuration = config('tenant_ai.verification');
        if (DB::getDriverName() !== 'pgsql' || ! is_array($configuration) || ($configuration['enabled'] ?? false) !== true
            || ($configuration['anthropic_egress_enabled'] ?? false) !== true) {
            throw new TenantAiStorageUnavailable;
        }
        if ($lock && (DB::transactionLevel() !== 1 || DB::selectOne('SHOW transaction_isolation', [], false)->transaction_isolation !== 'read committed')) {
            throw new TenantAiStorageUnavailable;
        }
        $root = $this->query('ai_gateway_controls', $lock)->where('deployment_id', $owner->deploymentId)->where('kind', 'global')->where('subject_key', 'root')->first();
        $initial = DB::table('tenant_ai_connections')->useWritePdo()->where('id', $request->connectionId)
            ->where('workspace_id', $owner->workspaceId)->where('deployment_id', $owner->deploymentId)->first();
        if ($root === null || $initial === null || ! is_array($manifest = $configuration['profiles'][$initial->binding_profile_id] ?? null)) {
            throw new TenantAiStorageUnavailable;
        }
        $profile = DB::table('ai_model_profiles')->useWritePdo()->where('id', $initial->binding_profile_id)->first();
        if ($profile === null || ! $profile->allows_customer || ! $profile->monetary_applicable || $profile->currency !== 'USD'
            || $profile->provider_key !== 'anthropic' || $profile->credential_family !== 'anthropic-api-key-v1'
            || ($manifest['profile_manifest_sha256'] ?? null) !== $profile->manifest_sha256
            || ($manifest['model'] ?? null) !== $profile->model_key || ($manifest['endpoint_policy_key'] ?? null) !== $profile->endpoint_policy_key
            || ($manifest['reservation_micro_usd'] ?? null) !== (int) $profile->reservation_micro_usd
            || ($manifest['output_envelope'] ?? null) !== (int) $profile->output_envelope
            || ! is_int($manifest['request_cost_ceiling_micro_usd'] ?? null) || $manifest['request_cost_ceiling_micro_usd'] < 0
            || $manifest['request_cost_ceiling_micro_usd'] > $manifest['reservation_micro_usd']
            || ! is_string($manifest['cost_approval_reference'] ?? null) || $manifest['cost_approval_reference'] === ''
            || ($manifest['verifier_policy_key'] ?? null) !== 'anthropic-model-metadata-v1'
            || preg_match('/\A[0-9a-f]{64}\z/', $manifest['verifier_policy_sha256'] ?? '') !== 1
            || ! is_array($account = $configuration['accounts'][$initial->payer_budget_id] ?? null)) {
            throw new TenantAiStorageUnavailable;
        }
        $controls = $this->query('ai_gateway_controls', $lock)->where('deployment_id', $owner->deploymentId)
            ->where(fn (Builder $query) => $query->where(fn (Builder $provider) => $provider->where('kind', 'provider')->where('subject_key', 'anthropic'))
                ->orWhere(fn (Builder $model) => $model->where('kind', 'model')->where('profile_id', $profile->id)))
            ->orderBy('id')->limit(3)->get()->values()->all();
        if (count($controls) !== 2) {
            throw new TenantAiStorageUnavailable;
        }
        $controls = [$root, ...$controls];
        $budgetIds = [$initial->payer_budget_id, $account['aggregate_budget_id'] ?? null, $account['usage_budget_id'] ?? null];
        if (count(array_unique($budgetIds)) !== 3 || in_array(null, $budgetIds, true)) {
            throw new TenantAiStorageUnavailable;
        }
        $budgets = $this->query('assistant_provider_controls', $lock)->whereIn('budget_id', $budgetIds)->orderBy('budget_id')->get()->values()->all();
        $settings = $this->query('tenant_ai_settings', $lock)->where('workspace_id', $owner->workspaceId)->where('deployment_id', $owner->deploymentId)->first();
        $connection = $this->query('tenant_ai_connections', $lock)->where('id', $request->connectionId)
            ->where('workspace_id', $owner->workspaceId)->where('deployment_id', $owner->deploymentId)->first();
        $credentials = $this->query('tenant_ai_credentials', $lock)->select(['id', 'connection_id', 'workspace_id', 'deployment_id', 'version_number',
            'state', 'verification_state', 'encryption_schema', 'wrap_revision', 'created_at', 'expires_at', 'secret_destroyed_at',
            'activated_generation', 'verification_operation_id', 'last_verified_at', 'verified_profile_id'])
            ->where('connection_id', $request->connectionId)->where('workspace_id', $owner->workspaceId)->where('deployment_id', $owner->deploymentId)
            ->whereIn('state', ['pending', 'active'])->orderBy('id')->limit(3)->get()->values()->all();
        $credential = collect($credentials)->first(fn ($row) => $row->state === 'pending');
        $active = collect($credentials)->first(fn ($row) => $row->state === 'active');
        if ($settings === null || $connection === null || $credential === null || count($credentials) > 2
            || $settings->mode !== 'customer_managed' || $settings->profile_id !== $profile->id || $settings->connection_id !== $connection->id
            || $settings->root_control_id !== $root->id || $settings->credential_version_id !== $request->selectedVersionId
            || (int) $settings->revision !== $request->settingsRevision || (int) $connection->revision !== $request->connectionRevision
            || (int) $connection->active_generation !== $request->activeGeneration || $active?->id !== $request->selectedVersionId
            || ($active !== null && (int) $active->activated_generation !== $request->activeGeneration)
            || $connection->disabled || $connection->revoked_at !== null || $connection->ownership_kind !== 'customer_managed'
            || $connection->binding_profile_id !== $profile->id || $connection->payer_budget_id !== $initial->payer_budget_id
            || $credential->id !== $request->credentialId || $credential->secret_destroyed_at !== null
            || ! in_array($credential->verification_state, ['unverified', 'failed'], true) || (int) $credential->encryption_schema !== 1) {
            throw new TenantAiStorageUnavailable;
        }
        $approval = $this->query('assistant_provider_tenants', $lock)->where('contract_version', 'gateway_v1')->where('purpose', 'connection_probe')
            ->where('workspace_id', $owner->workspaceId)->where('legal_entity_id', $context->legalEntityId)->where('environment', 'production')
            ->where('deployment_id', $owner->deploymentId)->where('profile_id', $profile->id)->where('ownership_kind', 'customer_managed')
            ->where('account_budget_id', $connection->payer_budget_id)->where('connection_id', $connection->id)
            ->where('selection_revision', $settings->revision)->where('entitlement_revision', $settings->entitlement_revision)
            ->whereNull('revoked_at')->orderBy('id')->first();
        if ($approval === null || $approval->owner_attribution_id !== $context->actorAttributionId
            || $approval->verifier_policy_sha256 !== $manifest['verifier_policy_sha256']
            || $approval->account_mapping_sha256 !== ($account['mapping_sha256'] ?? null)
            || $approval->disclosure_id !== ($account['disclosure_id'] ?? null)) {
            throw new TenantAiStorageUnavailable;
        }
        $acknowledgement = $this->query('assistant_provider_acknowledgements', $lock)->where('contract_version', 'gateway_v1')
            ->where('actor_attribution_id', $context->actorAttributionId)->where('workspace_id', $owner->workspaceId)
            ->where('disclosure_id', $approval->disclosure_id)->whereNull('revoked_at')->orderBy('id')->first();
        $now = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant', [], false)->instant);
        $context->authorize();
        if ($acknowledgement === null || $now->lt(CarbonImmutable::parse($profile->valid_from)) || ! $now->lt(CarbonImmutable::parse($profile->valid_until))
            || ! $now->lt(CarbonImmutable::parse($approval->expires_at)) || ($credential->expires_at !== null && ! $now->lt(CarbonImmutable::parse($credential->expires_at)))
            || ($account['deployment_id'] ?? null) !== $owner->deploymentId || ($account['workspace_public_id'] ?? null) !== $owner->workspacePublicId
            || ($account['legal_entity_public_id'] ?? null) !== $context->legalEntityPublicId || ($account['connection_id'] ?? null) !== $connection->id
            || ($account['profile_id'] ?? null) !== $profile->id || ($account['purpose'] ?? null) !== 'connection_probe'
            || ! is_string($account['egress_approval_reference'] ?? null) || $account['egress_approval_reference'] === ''
            || ! is_string($account['expires_at'] ?? null) || ! $now->lt(CarbonImmutable::parse($account['expires_at']))
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $account['organization_id'] ?? '') !== 1
            || preg_match('/\Awrkspc_[A-Za-z0-9]{1,120}\z/', $account['workspace_id'] ?? '') !== 1 || count($budgets) !== 3) {
            throw new TenantAiStorageUnavailable;
        }
        $references = [];
        foreach ($controls as $control) {
            if ($control->circuit_blocked) {
                throw new TenantAiStorageUnavailable;
            }
            $references[] = ['gateway_control', $control->id, (string) $control->revision];
        }
        foreach ($budgets as $budget) {
            $role = $budget->budget_id === $connection->payer_budget_id ? 'account' : ($budget->budget_id === $account['aggregate_budget_id'] ? 'aggregate' : 'usage');
            if ($budget->contract_version !== 'gateway_v1' || $budget->deployment_id !== $owner->deploymentId || $budget->account_role !== $role
                || $budget->ownership_kind !== ($role === 'usage' ? 'shared_usage' : 'customer_managed')
                || ($role !== 'usage' && (int) $budget->workspace_id !== $owner->workspaceId)
                || ($role === 'account' && ($budget->provider_key !== 'anthropic' || $budget->account_reference !== ($account['account_reference'] ?? null)))
                || ! $budget->enabled || $budget->circuit_blocked || $budget->approval_reference === null || $budget->approval_expires_at === null
                || ! $now->lt(CarbonImmutable::parse($budget->approval_expires_at))) {
                throw new TenantAiStorageUnavailable;
            }
            $references[] = ['budget_control', $budget->budget_id, (string) $budget->revision];
        }
        $references[] = ['owner_approval', (string) $approval->id, (string) $approval->entitlement_revision];
        usort($references, fn (array $a, array $b): int => strcmp($a[0].':'.$a[1], $b[0].':'.$b[1]));

        return new TenantAiVerificationPolicy($manifest, $account, $profile, $settings, $connection, $credential, $active, $approval,
            $acknowledgement, $controls, array_values($budgets), array_values($credentials), $references, hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)));
    }

    private function query(string $table, bool $lock): Builder
    {
        $query = DB::table($table)->useWritePdo();

        return $lock ? $query->lockForUpdate() : $query;
    }
}
