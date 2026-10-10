<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Active-aware local lifecycle. An active credential still grants no gateway inference authority. */
final class TenantAiActiveLifecycle
{
    public function promote(TenantAiCredentialVerifier $session, TenantAiVerificationContext $context, CredentialPromotionPermit $permit): TenantAiVerificationResult
    {
        $session->consumePromotion($context, $permit);

        return $session->completePromotion($context, $permit);
    }

    /** @return array<string, mixed> */
    public function candidate(TenantAiContext $context, string $connectionId, int $settingsRevision, int $connectionRevision,
        ?string $expectedPendingId, #[\SensitiveParameter] string $secret): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $settingsRevision, $connectionRevision, $expectedPendingId, $secret): array {
            [$settings, $connection, $credentials] = $this->locked($context, $connectionId, $settingsRevision, $connectionRevision);
            $pending = collect($credentials)->first(fn ($row) => $row->state === 'pending');
            if ($pending?->id !== $expectedPendingId) {
                throw new TenantAiStorageUnavailable;
            }
            $profile = DB::table('ai_model_profiles')->useWritePdo()->where('id', $connection->binding_profile_id)->first();
            $manifest = config('tenant_ai.verification.profiles.'.$connection->binding_profile_id);
            if ($profile === null || ! is_array($manifest) || ($manifest['profile_manifest_sha256'] ?? null) !== $profile->manifest_sha256
                || ! $profile->allows_customer || $connection->provider_key !== 'anthropic') {
                throw new TenantAiStorageUnavailable;
            }
            $last = DB::table('tenant_ai_credentials')->useWritePdo()->where('connection_id', $connectionId)->orderByDesc('version_number')->value('version_number');
            $version = TenantAiVerificationAdmission::add((int) ($last ?? 0), 1);
            $id = (string) Str::uuid();
            $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            $envelope = TenantAiEnvelope::seal($secret, ['schema' => 1, 'deployment_id' => $context->deploymentId,
                'workspace_public_id' => $context->workspacePublicId, 'connection_id' => $connectionId, 'version_id' => $id,
                'provider' => $connection->provider_key, 'credential_family' => $connection->credential_family,
                'endpoint_policy' => $connection->endpoint_policy_key], new TenantAiKeyFile);
            $operation = (string) Str::uuid();
            if ($pending !== null) {
                $this->destroy($pending, $time);
                foreach (['credential_revoked', 'credential_destroyed'] as $event) {
                    $this->audit($context, $operation, $connectionId, $pending->id, $event, 'revoked');
                }
            }
            $actor = $context->authorize();
            $envelope->insert($context, ['id' => $id, 'connection_id' => $connectionId, 'workspace_id' => $context->workspaceId,
                'deployment_id' => $context->deploymentId, 'version_number' => $version, 'creator_attribution_id' => $actor->attribution_id,
                'creator_user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
            DB::table('tenant_ai_connections')->where('id', $connectionId)->update(['revision' => TenantAiVerificationAdmission::add($connectionRevision, 1), 'updated_at' => $time]);
            $this->audit($context, $operation, $connectionId, $id, 'credential_configured', 'pending');

            return ['id' => $id, 'connection_id' => $connectionId, 'state' => 'pending', 'verification_state' => 'unverified'];
        });
    }

    /** @return array<string, mixed> */
    public function revoke(TenantAiEmergencyContext $context, string $connectionId, string $credentialId, int $settingsRevision, int $connectionRevision): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $credentialId, $settingsRevision, $connectionRevision): array {
            [$settings, $connection, $credentials] = $this->locked($context, $connectionId, $settingsRevision, $connectionRevision, $credentialId);
            $credential = collect($credentials)->first(fn ($row) => $row->id === $credentialId);
            if ($credential === null) {
                throw new TenantAiStorageUnavailable;
            }
            if (in_array($credential->state, ['replaced', 'revoked'], true)) {
                return ['id' => $credentialId, 'state' => $credential->state];
            }
            $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            $operation = (string) Str::uuid();
            $this->destroy($credential, $time);
            $changes = ['revision' => TenantAiVerificationAdmission::add($connectionRevision, 1), 'updated_at' => $time];
            if ($credential->state === 'active') {
                $changes['active_generation'] = TenantAiVerificationAdmission::add((int) $connection->active_generation, 1);
                if ($settings->credential_version_id === $credentialId) {
                    $this->clearSelection($context, $settings, $time);
                    $this->audit($context, $operation, $connectionId, $credentialId, 'selection_changed', 'disabled');
                }
            }
            DB::table('tenant_ai_connections')->where('id', $connectionId)->update($changes);
            foreach (['credential_revoked', 'credential_destroyed'] as $event) {
                $this->audit($context, $operation, $connectionId, $credentialId, $event, 'revoked');
            }

            return ['id' => $credentialId, 'state' => 'revoked'];
        });
    }

    /** @return array<string, mixed> */
    public function disableConnection(TenantAiEmergencyContext $context, string $connectionId, int $settingsRevision, int $connectionRevision): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $settingsRevision, $connectionRevision): array {
            [$settings, $connection] = $this->locked($context, $connectionId, $settingsRevision, $connectionRevision);
            $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            $operation = (string) Str::uuid();
            if (! $connection->disabled) {
                DB::table('tenant_ai_connections')->where('id', $connectionId)->update([
                    'disabled' => true, 'revision' => TenantAiVerificationAdmission::add($connectionRevision, 1), 'updated_at' => $time]);
                $this->audit($context, $operation, $connectionId, null, 'selection_changed', 'disabled');
            }
            if ($settings->connection_id === $connectionId) {
                $this->clearSelection($context, $settings, $time);
                $this->audit($context, $operation, $connectionId, $settings->credential_version_id, 'selection_changed', 'disabled');
            }

            return ['connection_id' => $connectionId, 'disabled' => true];
        });
    }

    /** @return array<string, mixed> */
    public function disableWorkspace(TenantAiEmergencyContext $context, int $settingsRevision): array
    {
        return (new TenantAiLifecycle(new TenantAiKeyFile))->disableWorkspace($context, $settingsRevision);
    }

    /** @param \Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function transaction(TenantAiContext|TenantAiEmergencyContext $context, #[\SensitiveParameter] \Closure $operation): array
    {
        if (DB::transactionLevel() !== 0 || DB::getDriverName() !== 'pgsql') {
            throw new TenantAiStorageUnavailable;
        }
        try {
            $deadline = hrtime(true) / 1e9 + 10;

            return DB::transaction(function () use ($context, $operation, $deadline): array {
                TenantAiVerificationAdmission::limits($deadline);
                $context->authorize();
                DB::table('ai_gateway_controls')->where('deployment_id', $context->deploymentId)->where('kind', 'global')
                    ->where('subject_key', 'root')->lockForUpdate()->firstOrFail();
                $context->authorize();
                $result = $operation();
                $context->authorize();
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new TenantAiStorageUnavailable;
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return $result;
            }, 1);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** @return array{\stdClass, \stdClass, list<\stdClass>} */
    private function locked(TenantAiContext|TenantAiEmergencyContext $context, string $id, int $settingsRevision, int $connectionRevision, ?string $exact = null): array
    {
        $settings = DB::table('tenant_ai_settings')->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->lockForUpdate()->first();
        $connection = DB::table('tenant_ai_connections')->where('id', $id)->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->lockForUpdate()->first();
        if ($settings === null || $connection === null || $connection->revoked_at !== null || $connection->ownership_kind !== 'customer_managed'
            || (int) $settings->revision !== $settingsRevision || (int) $connection->revision !== $connectionRevision) {
            throw new TenantAiStorageUnavailable;
        }
        $query = DB::table('tenant_ai_credentials')->select(['id', 'state', 'verification_state', 'secret_destroyed_at', 'activated_generation'])
            ->where('connection_id', $id)->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId);
        $query->where(fn ($query) => $query->whereIn('state', ['pending', 'active'])->when($exact !== null, fn ($query) => $query->orWhere('id', $exact)));
        $credentials = $query->orderBy('id')->limit(4)->lockForUpdate()->get()->values()->all();
        if (count($credentials) > 3) {
            throw new TenantAiStorageUnavailable;
        }

        return [$settings, $connection, array_values($credentials)];
    }

    private function destroy(\stdClass $credential, string $time): void
    {
        if (! in_array($credential->state, ['pending', 'active'], true) || $credential->secret_destroyed_at !== null) {
            throw new TenantAiStorageUnavailable;
        }
        DB::table('tenant_ai_credentials')->where('id', $credential->id)->update(['state' => 'revoked', 'revoked_at' => $time,
            'secret_destroyed_at' => $time, 'secret_ciphertext' => null, 'wrapped_dek' => null, 'kek_version' => null, 'updated_at' => $time]);
    }

    private function clearSelection(TenantAiContext|TenantAiEmergencyContext $context, \stdClass $settings, string $time): void
    {
        DB::table('tenant_ai_settings')->where('workspace_id', $context->workspaceId)->update(['mode' => 'disabled', 'profile_id' => null,
            'connection_id' => null, 'credential_version_id' => null, 'revision' => TenantAiVerificationAdmission::add((int) $settings->revision, 1), 'updated_at' => $time]);
    }

    private function audit(TenantAiContext|TenantAiEmergencyContext $context, string $operation, string $connection, ?string $credential, string $event, string $outcome): void
    {
        $actor = $context->authorize();
        RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event('assistant.ai.'.$event)
            ->withProperties(['actor_kind' => 'human', 'actor_attribution_id' => $actor->attribution_id, 'workspace_public_id' => $context->workspacePublicId,
                'deployment_id' => $context->deploymentId, 'connection_id' => $connection, 'credential_version_id' => $credential, 'operation_id' => $operation,
                'outcome' => $outcome, 'user_agent' => null, 'ip_address' => null])->log('AI credential lifecycle changed'));
    }
}
