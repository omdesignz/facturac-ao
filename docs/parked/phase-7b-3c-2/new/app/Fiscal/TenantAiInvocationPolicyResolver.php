<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiInvocationRefused;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Every gate of one customer-managed invocation, read freshly from the primary. Nothing is caller-selectable. */
final class TenantAiInvocationPolicyResolver
{
    /** A customer profile is admissible only when its wire contract equals the accepted adapter constants. */
    private const WIRE = ['provider_key', 'credential_family', 'endpoint_policy_key', 'model_key', 'response_model_key', 'protocol_key',
        'price_key', 'purpose', 'monetary_applicable', 'currency', 'input_envelope', 'output_envelope', 'request_byte_limit',
        'response_byte_limit', 'deadline_ms', 'reservation_micro_usd'];

    public function resolve(AssistantProviderInvocation $invocation, string $realm, bool $lock): TenantAiInvocationPolicy
    {
        $context = $invocation->context;
        $configuration = config('tenant_ai.inference');
        $deployment = config('tenant_ai.deployment_id');
        if (DB::getDriverName() !== 'pgsql' || config('assistant.enabled') !== true || ! is_array($configuration)
            || ($configuration['enabled'] ?? false) !== true || ($configuration['anthropic_egress_enabled'] ?? false) !== true || ! is_string($deployment)) {
            $this->refuse(AiInvocationDenial::RouteDisabled);
        }
        if ($lock && (DB::transactionLevel() !== 1 || DB::selectOne('SHOW transaction_isolation', [], false)->transaction_isolation !== 'read committed')) {
            $this->refuse(AiInvocationDenial::RuntimeUnsupported);
        }
        $permissions = $invocation->permissions();
        $workspaceId = $context->execution->workspaceId;
        $initial = DB::table('tenant_ai_settings')->useWritePdo()->where('workspace_id', $workspaceId)->where('deployment_id', $deployment)->first();
        if ($initial === null || $initial->mode !== 'customer_managed' || $initial->connection_id === null || $initial->credential_version_id === null) {
            $this->refuse(AiInvocationDenial::AuthorityChanged);
        }
        $link = DB::table('tenant_ai_connections')->useWritePdo()->where('id', $initial->connection_id)
            ->where('workspace_id', $workspaceId)->where('deployment_id', $deployment)->first();
        if ($link === null || ! is_string($link->payer_budget_id)) {
            $this->refuse(AiInvocationDenial::ConnectionUnavailable);
        }
        if (! is_array($manifest = $configuration['profiles'][$initial->profile_id] ?? null)) {
            $this->refuse(AiInvocationDenial::ProfileUnsupported);
        }
        $account = $configuration['accounts'][$link->payer_budget_id] ?? null;
        $budgetIds = is_array($account) ? [$link->payer_budget_id, $account['aggregate_budget_id'] ?? null, $account['usage_budget_id'] ?? null] : [];
        if (count(array_filter($budgetIds, is_string(...))) !== 3 || count(array_unique($budgetIds)) !== 3) {
            $this->refuse(AiInvocationDenial::EntitlementMissing);
        }

        // Canonical lock order: root, provider and model controls, budgets, settings, connection, credentials, approval, acknowledgement.
        $root = $this->query('ai_gateway_controls', $lock)->where('id', $initial->root_control_id)->where('deployment_id', $deployment)
            ->where('kind', 'global')->where('subject_key', 'root')->first();
        $controls = $this->query('ai_gateway_controls', $lock)->where('deployment_id', $deployment)
            ->where(fn (Builder $query) => $query->where(fn (Builder $provider) => $provider->where('kind', 'provider')->where('subject_key', 'anthropic'))
                ->orWhere(fn (Builder $model) => $model->where('kind', 'model')->where('profile_id', $initial->profile_id)))
            ->orderBy('id')->limit(3)->get()->values()->all();
        $budgets = $this->query('assistant_provider_controls', $lock)->whereIn('budget_id', $budgetIds)->orderBy('budget_id')->get()->values()->all();
        $settings = $this->query('tenant_ai_settings', $lock)->where('workspace_id', $workspaceId)->where('deployment_id', $deployment)->first();
        $connection = $this->query('tenant_ai_connections', $lock)->where('id', $initial->connection_id)
            ->where('workspace_id', $workspaceId)->where('deployment_id', $deployment)->first();
        $credentials = $this->query('tenant_ai_credentials', $lock)->select(['id', 'connection_id', 'workspace_id', 'deployment_id', 'version_number',
            'state', 'verification_state', 'encryption_schema', 'wrap_revision', 'created_at', 'expires_at', 'secret_destroyed_at',
            'activated_generation', 'verification_operation_id', 'last_verified_at', 'verified_profile_id'])
            ->where('connection_id', $initial->connection_id)->where('workspace_id', $workspaceId)->where('deployment_id', $deployment)
            ->whereIn('state', ['pending', 'active'])->orderBy('id')->limit(3)->get()->values()->all();
        $now = TenantAiVerificationAdmission::instant();

        if ($settings === null || $settings->mode !== 'customer_managed' || $settings->root_control_id !== $initial->root_control_id
            || $settings->profile_id !== $initial->profile_id || $settings->connection_id !== $initial->connection_id
            || $settings->credential_version_id !== $initial->credential_version_id) {
            $this->refuse(AiInvocationDenial::AuthorityChanged);
        }
        $profile = DB::table('ai_model_profiles')->useWritePdo()->where('id', $settings->profile_id)->first();
        $accepted = TenantAiCatalogue::profile();
        if ($profile === null || ! $profile->allows_customer || ($manifest['profile_manifest_sha256'] ?? null) !== $profile->manifest_sha256
            || ($manifest['model'] ?? null) !== $profile->model_key || ($manifest['endpoint_policy_key'] ?? null) !== $profile->endpoint_policy_key
            || ($manifest['reservation_micro_usd'] ?? null) !== (int) $profile->reservation_micro_usd
            || ($manifest['output_envelope'] ?? null) !== (int) $profile->output_envelope
            || $now->lt(CarbonImmutable::parse($profile->valid_from)) || ! $now->lt(CarbonImmutable::parse($profile->valid_until))) {
            $this->refuse(AiInvocationDenial::ProfileUnsupported);
        }
        foreach (self::WIRE as $field) {
            $value = is_int($accepted[$field]) ? (int) $profile->{$field} : $profile->{$field};
            if ($value !== $accepted[$field]) {
                $this->refuse(AiInvocationDenial::ProfileUnsupported);
            }
        }
        if ($connection === null || $connection->disabled || $connection->revoked_at !== null || $connection->ownership_kind !== 'customer_managed'
            || $connection->binding_profile_id !== $profile->id || $connection->payer_budget_id !== $link->payer_budget_id) {
            $this->refuse(AiInvocationDenial::ConnectionUnavailable);
        }
        $credential = collect($credentials)->first(fn (\stdClass $row): bool => $row->id === $settings->credential_version_id);
        if ($credential === null || count($credentials) > 2 || $credential->state !== 'active' || $credential->verification_state !== 'verified'
            || $credential->secret_destroyed_at !== null || (int) $credential->encryption_schema !== 1 || $credential->verified_profile_id !== $profile->id) {
            $this->refuse(AiInvocationDenial::CredentialNotActive);
        }
        if ($credential->expires_at !== null && ! $now->lt(CarbonImmutable::parse($credential->expires_at))) {
            $this->refuse(AiInvocationDenial::CredentialExpired);
        }
        if ((int) $credential->activated_generation < 1 || (int) $credential->activated_generation !== (int) $connection->active_generation) {
            $this->refuse(AiInvocationDenial::GenerationMismatch);
        }

        $workspacePublicId = DB::table('workspaces')->useWritePdo()->where('id', $workspaceId)->value('public_id');
        $entityPublicId = DB::table('legal_entities')->useWritePdo()->where('id', $context->execution->legalEntityId)
            ->where('workspace_id', $workspaceId)->value('public_id');
        if (! is_string($workspacePublicId) || ! is_string($entityPublicId) || strtolower($workspacePublicId) !== $context->workspacePublicId
            || strtolower($entityPublicId) !== $context->entityPublicId) {
            $this->refuse(AiInvocationDenial::BindingMismatch);
        }
        $this->receipt($receipt = DB::table('assistant_provider_attempts')->useWritePdo()->where('id', $credential->verification_operation_id)->first(),
            $credential, $connection, $profile, $account, $workspacePublicId, $entityPublicId, $deployment, $realm);

        $verification = config('tenant_ai.verification.accounts.'.$connection->payer_budget_id.'.disclosure_id');
        if ((int) $settings->entitlement_revision < 1 || ($account['deployment_id'] ?? null) !== $deployment
            || ($account['workspace_public_id'] ?? null) !== $workspacePublicId || ($account['connection_id'] ?? null) !== $connection->id
            || ($account['profile_id'] ?? null) !== $profile->id || ($account['purpose'] ?? null) !== 'assistant_intent'
            || ! is_string($account['egress_approval_reference'] ?? null) || $account['egress_approval_reference'] === ''
            || ! is_string($account['expires_at'] ?? null) || ! $now->lt(CarbonImmutable::parse($account['expires_at']))
            || preg_match('/\A[0-9a-f]{64}\z/', $account['mapping_sha256'] ?? '') !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $account['disclosure_id'] ?? '') !== 1
            || $account['disclosure_id'] === $verification) {
            $this->refuse(AiInvocationDenial::EntitlementMissing);
        }
        if (($account['legal_entity_public_id'] ?? null) !== $entityPublicId) {
            $this->refuse(AiInvocationDenial::BindingMismatch);
        }

        $approval = $this->query('assistant_provider_tenants', $lock)->where('contract_version', 'gateway_v1')->where('purpose', 'assistant_intent')
            ->where('workspace_id', $workspaceId)->where('legal_entity_id', $context->execution->legalEntityId)->where('environment', 'production')
            ->where('deployment_id', $deployment)->where('profile_id', $profile->id)->where('ownership_kind', 'customer_managed')
            ->where('account_budget_id', $connection->payer_budget_id)->where('connection_id', $connection->id)
            ->where('selection_revision', $settings->revision)->where('entitlement_revision', $settings->entitlement_revision)
            ->whereNull('revoked_at')->orderBy('id')->first();
        if ($approval === null || $approval->account_mapping_sha256 !== $account['mapping_sha256'] || $approval->disclosure_id !== $account['disclosure_id']
            || ! $now->lt(CarbonImmutable::parse($approval->expires_at))
            || ! DB::table('workspace_memberships')->useWritePdo()->join('users', 'users.id', '=', 'workspace_memberships.user_id')
                ->where('workspace_memberships.workspace_id', $workspaceId)->where('workspace_memberships.is_active', true)
                ->where('workspace_memberships.role', 'owner')->where('users.attribution_id', $approval->owner_attribution_id)->exists()) {
            $this->refuse(AiInvocationDenial::ApprovalMissing);
        }
        $acknowledgement = $this->query('assistant_provider_acknowledgements', $lock)->where('contract_version', 'gateway_v1')
            ->where('actor_attribution_id', $context->actorAttributionId)->where('workspace_id', $workspaceId)
            ->where('disclosure_id', $approval->disclosure_id)->whereNull('revoked_at')->orderBy('id')->first();
        if ($acknowledgement === null) {
            $this->refuse(AiInvocationDenial::AcknowledgementMissing);
        }

        $references = [];
        $controls = [$root, ...$controls];
        if ($root === null || count($controls) !== 3) {
            $this->refuse(AiInvocationDenial::ControlBlocked);
        }
        foreach ($controls as $control) {
            if (! $control->enabled || $control->circuit_blocked || $control->approval_expires_at === null
                || ! $now->lt(CarbonImmutable::parse($control->approval_expires_at))) {
                $this->refuse(AiInvocationDenial::ControlBlocked);
            }
            $references[] = ['gateway_control', $control->id, (string) $control->revision];
        }
        if (count($budgets) !== 3) {
            $this->refuse(AiInvocationDenial::ControlBlocked);
        }
        foreach ($budgets as $budget) {
            $role = $budget->budget_id === $connection->payer_budget_id ? 'account' : ($budget->budget_id === $account['aggregate_budget_id'] ? 'aggregate' : 'usage');
            if ($budget->contract_version !== 'gateway_v1' || $budget->deployment_id !== $deployment || $budget->account_role !== $role
                || $budget->ownership_kind !== ($role === 'usage' ? 'shared_usage' : 'customer_managed')
                || ($role !== 'usage' && (int) $budget->workspace_id !== $workspaceId)
                || ($role === 'account' && ($budget->provider_key !== 'anthropic' || $budget->account_reference !== ($account['account_reference'] ?? null)))) {
                $this->refuse(AiInvocationDenial::EntitlementMissing);
            }
            if (! $budget->enabled || $budget->circuit_blocked || $budget->approval_reference === null || $budget->approval_expires_at === null
                || ! $now->lt(CarbonImmutable::parse($budget->approval_expires_at))) {
                $this->refuse(AiInvocationDenial::ControlBlocked);
            }
            $references[] = ['budget_control', $budget->budget_id, (string) $budget->revision];
        }
        $references[] = ['owner_approval', (string) $approval->id, (string) $approval->entitlement_revision];
        usort($references, fn (array $a, array $b): int => strcmp($a[0].':'.$a[1], $b[0].':'.$b[1]));
        $tools = AiToolCeiling::tools($settings, $permissions);
        if ($tools === []) {
            $this->refuse(AiInvocationDenial::RouteDisabled);
        }

        return new TenantAiInvocationPolicy($manifest, $account, $profile, $settings, $connection, $credential, $receipt, $approval, $acknowledgement,
            $controls, array_values($budgets), array_values($credentials), $references, $permissions, $tools, $workspacePublicId, $entityPublicId, $realm,
            hash('sha256', json_encode([$deployment, $configuration], JSON_THROW_ON_ERROR)));
    }

    /** Authentic promoted receipt of exactly this version and generation; database flags alone never suffice.
     * @param  array<string, mixed>  $account
     */
    private function receipt(?\stdClass $receipt, \stdClass $credential, \stdClass $connection, \stdClass $profile, array $account,
        string $workspacePublicId, string $entityPublicId, string $deployment, string $realm): void
    {
        if ($receipt === null || $receipt->contract_version !== 'gateway_v1' || $receipt->purpose !== 'connection_probe'
            || $receipt->promotion_disposition !== 'promoted' || $receipt->evidence_realm !== $realm
            || ! is_string($receipt->receipt_key_id) || ! is_string($receipt->receipt_mac) || ! is_string($receipt->verifier_policy_sha256)) {
            $this->refuse(AiInvocationDenial::ReceiptUntrusted);
        }
        try {
            $key = (new TenantAiVerificationReceiptKeyFile)->read($receipt->receipt_key_id, $receipt->deployment_id, $receipt->verifier_policy_sha256);
            $authentic = TenantAiVerificationReceipt::authentic(TenantAiVerificationReceipt::fields((array) $receipt), $receipt->receipt_mac, $key);
        } catch (\Throwable) {
            $authentic = false;
        } finally {
            unset($key);
        }
        if (! $authentic || $receipt->credential_version_id !== $credential->id || $receipt->connection_id !== $connection->id
            || $receipt->credential_created_at !== $credential->created_at || (int) $receipt->credential_version_number !== (int) $credential->version_number) {
            $this->refuse(AiInvocationDenial::ReceiptUntrusted);
        }
        if ((int) $receipt->committed_activated_generation !== (int) $credential->activated_generation) {
            $this->refuse(AiInvocationDenial::GenerationMismatch);
        }
        if ($receipt->workspace_public_id !== $workspacePublicId || $receipt->legal_entity_public_id !== $entityPublicId
            || $receipt->deployment_id !== $deployment || $receipt->profile_id !== $profile->id
            || $receipt->profile_manifest_sha256 !== $profile->manifest_sha256 || $receipt->provider_key !== $profile->provider_key
            || $receipt->credential_family !== $profile->credential_family || $receipt->endpoint_policy_key !== $profile->endpoint_policy_key
            || $receipt->account_mapping_sha256 !== ($account['mapping_sha256'] ?? null)) {
            $this->refuse(AiInvocationDenial::BindingMismatch);
        }
    }

    private function refuse(AiInvocationDenial $reason): never
    {
        throw new TenantAiInvocationRefused($reason);
    }

    private function query(string $table, bool $lock): Builder
    {
        $query = DB::table($table)->useWritePdo();

        return $lock ? $query->lockForUpdate() : $query;
    }
}
