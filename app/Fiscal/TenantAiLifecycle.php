<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\TenantAiCredential;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** PA1 below-active operations. No promotion, verification, decryption or gateway seam. */
final readonly class TenantAiLifecycle
{
    public function __construct(private TenantAiKeyFile $keys) {}

    /** @return array<string, mixed> */
    public function candidate(TenantAiContext $context, string $connectionId, int $settingsRevision,
        int $connectionRevision, ?string $expectedPendingId, #[\SensitiveParameter] string $secret): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $settingsRevision, $connectionRevision, $expectedPendingId, $secret): array {
            $settings = $this->settings($context, $settingsRevision);
            $connection = $this->connection($context, $connectionId, $connectionRevision);
            $pending = $this->credentials($context, $connectionId)->where('state', 'pending')->orderBy('id')->lockForUpdate()->first();
            if (($pending?->id) !== $expectedPendingId) {
                throw new TenantAiStorageUnavailable;
            }
            $profile = TenantAiCatalogue::profile();
            $actual = DB::table('ai_model_profiles')->useWritePdo()->where('id', $connection->binding_profile_id)->first();
            if ($connection->binding_profile_id !== $profile['id'] || $actual === null || $actual->manifest_sha256 !== $profile['manifest_sha256']) {
                throw new TenantAiStorageUnavailable;
            }
            $lastVersion = $this->credentials($context, $connectionId)->select('version_number')->orderByDesc('version_number')->value('version_number');
            $version = $lastVersion === null ? 1 : $this->increment((int) $lastVersion);
            $revision = $this->increment($connectionRevision);
            $id = (string) Str::uuid();
            $time = now()->utc()->toIso8601String();
            $envelope = TenantAiEnvelope::seal($secret, ['schema' => 1, 'deployment_id' => $context->deploymentId,
                'workspace_public_id' => $context->workspacePublicId, 'connection_id' => $connectionId, 'version_id' => $id,
                'provider' => $connection->provider_key, 'credential_family' => $connection->credential_family,
                'endpoint_policy' => $connection->endpoint_policy_key], $this->keys);
            if ($pending !== null) {
                $this->destroy($context, $connectionId, $pending, $time);
            }
            $actor = $context->authorize();
            $envelope->insert($context, ['id' => $id, 'connection_id' => $connectionId, 'workspace_id' => $context->workspaceId,
                'deployment_id' => $context->deploymentId, 'version_number' => $version, 'creator_attribution_id' => $actor->attribution_id,
                'creator_user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
            $this->advanceConnection($context, $connection, $revision, $time);
            $operation = (string) Str::uuid();
            if ($pending !== null) {
                foreach (['credential_revoked', 'credential_destroyed'] as $event) {
                    $this->audit($context, $event, 'revoked', $operation, $settings, $connection, $pending->id, $settingsRevision, $revision);
                }
            }
            $this->audit($context, 'credential_configured', 'pending', $operation, $settings, $connection, $id, $settingsRevision, $revision);

            return $this->metadata($context, $id);
        });
    }

    /** @return array<string, mixed> */
    public function revoke(TenantAiEmergencyContext $context, string $connectionId, string $credentialId,
        int $settingsRevision, int $connectionRevision): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $credentialId, $settingsRevision, $connectionRevision): array {
            $settings = $this->settings($context, $settingsRevision);
            $connection = $this->connection($context, $connectionId, $connectionRevision);
            $credential = $this->credentials($context, $connectionId)->where('id', $credentialId)->lockForUpdate()->first();
            if ($credential === null || ! in_array($credential->state, ['pending', 'revoked'], true)) {
                throw new TenantAiStorageUnavailable;
            }
            if ($credential->state === 'revoked') {
                if ($credential->secret_destroyed_at === null) {
                    throw new TenantAiStorageUnavailable;
                }

                return $this->metadata($context, $credentialId);
            }
            $revision = $this->increment($connectionRevision);
            $time = now()->utc()->toIso8601String();
            $this->destroy($context, $connectionId, $credential, $time);
            $this->advanceConnection($context, $connection, $revision, $time);
            $operation = (string) Str::uuid();
            foreach (['credential_revoked', 'credential_destroyed'] as $event) {
                $this->audit($context, $event, 'revoked', $operation, $settings, $connection, $credentialId, $settingsRevision, $revision);
            }

            return $this->metadata($context, $credentialId);
        });
    }

    /** @return array<string, mixed> */
    public function disableWorkspace(TenantAiEmergencyContext $context, int $settingsRevision): array
    {
        return $this->transaction($context, function () use ($context, $settingsRevision): array {
            $settings = $this->settings($context, $settingsRevision);
            if ($settings->mode !== 'disabled') {
                $revision = $this->increment($settingsRevision);
                $changed = DB::table('tenant_ai_settings')->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)
                    ->where('revision', $settingsRevision)->update(['mode' => 'disabled', 'profile_id' => null, 'connection_id' => null,
                        'credential_version_id' => null, 'revision' => $revision, 'updated_at' => now()->utc()->toIso8601String()]);
                $this->requireOne($changed);
                $operation = (string) Str::uuid();
                $this->audit($context, 'mode_changed', 'disabled', $operation, $settings, null, null, $revision, null);
                if ($settings->profile_id !== null || $settings->connection_id !== null || $settings->credential_version_id !== null) {
                    $this->audit($context, 'selection_changed', 'disabled', $operation, $settings, null, null, $revision, null);
                }
            }

            return ['mode' => 'disabled'];
        });
    }

    /** @return array<string, mixed> */
    public function disableConnection(TenantAiEmergencyContext $context, string $connectionId, int $settingsRevision, int $connectionRevision): array
    {
        return $this->transaction($context, function () use ($context, $connectionId, $settingsRevision, $connectionRevision): array {
            $settings = $this->settings($context, $settingsRevision);
            $connection = $this->connection($context, $connectionId, $connectionRevision);
            if (! $connection->disabled) {
                $revision = $this->increment($connectionRevision);
                $this->advanceConnection($context, $connection, $revision, now()->utc()->toIso8601String(), ['disabled' => true]);
                $this->audit($context, 'selection_changed', 'disabled', (string) Str::uuid(), $settings, $connection, null, $settingsRevision, $revision);
            }

            return ['connection_id' => $connectionId, 'disabled' => true];
        });
    }

    /** @param \Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function transaction(TenantAiContext|TenantAiEmergencyContext $context, #[\SensitiveParameter] \Closure $operation): array
    {
        try {
            $context->authorize();

            return DB::transaction(function () use ($context, $operation): array {
                if (DB::getDriverName() === 'pgsql' && DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
                    throw new TenantAiStorageUnavailable;
                }
                $root = DB::table('ai_gateway_controls')->where('deployment_id', $context->deploymentId)
                    ->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->first();
                if ($root === null) {
                    throw new TenantAiStorageUnavailable;
                }
                $context->authorize();
                $result = $operation();
                $context->authorize();

                return $result;
            }, 1);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    private function settings(TenantAiContext|TenantAiEmergencyContext $context, int $expectedRevision): \stdClass
    {
        $settings = DB::table('tenant_ai_settings')->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->lockForUpdate()->first();
        if ($settings === null || (int) $settings->revision !== $expectedRevision || $expectedRevision < 1) {
            throw new TenantAiStorageUnavailable;
        }

        return $settings;
    }

    private function connection(TenantAiContext|TenantAiEmergencyContext $context, string $id, int $expectedRevision): \stdClass
    {
        $connection = DB::table('tenant_ai_connections')->where('id', $id)->where('workspace_id', $context->workspaceId)
            ->where('deployment_id', $context->deploymentId)->lockForUpdate()->first();
        if ($connection === null || $connection->revoked_at !== null || $connection->ownership_kind !== 'customer_managed'
            || (int) $connection->revision !== $expectedRevision || $expectedRevision < 1
            || $this->credentials($context, $id)->whereIn('state', ['active', 'replaced'])->exists()) {
            throw new TenantAiStorageUnavailable;
        }

        return $connection;
    }

    private function credentials(TenantAiContext|TenantAiEmergencyContext $context, string $connectionId): Builder
    {
        return DB::table('tenant_ai_credentials')->useWritePdo()->select(['id', 'connection_id', 'state', 'version_number', 'verification_state', 'secret_destroyed_at'])
            ->where('connection_id', $connectionId)->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId);
    }

    private function destroy(TenantAiContext|TenantAiEmergencyContext $context, string $connectionId, \stdClass $credential, string $time): void
    {
        if ($credential->state !== 'pending' || $credential->verification_state !== 'unverified' || $credential->secret_destroyed_at !== null) {
            throw new TenantAiStorageUnavailable;
        }
        $this->requireOne($this->credentials($context, $connectionId)->where('id', $credential->id)->where('state', 'pending')
            ->update(['state' => 'revoked', 'revoked_at' => $time, 'secret_destroyed_at' => $time, 'secret_ciphertext' => null,
                'wrapped_dek' => null, 'kek_version' => null, 'updated_at' => $time]));
    }

    /** @param array{disabled?: bool} $extra */
    private function advanceConnection(TenantAiContext|TenantAiEmergencyContext $context, \stdClass $connection, int $revision, string $time, array $extra = []): void
    {
        $this->requireOne(DB::table('tenant_ai_connections')->where('id', $connection->id)->where('workspace_id', $context->workspaceId)
            ->where('deployment_id', $context->deploymentId)->where('revision', $connection->revision)
            ->update([...$extra, 'revision' => $revision, 'updated_at' => $time]));
    }

    private function increment(int $value): int
    {
        if ($value < 1 || $value === PHP_INT_MAX) {
            throw new TenantAiStorageUnavailable;
        }

        return $value + 1;
    }

    private function requireOne(int $changed): void
    {
        if ($changed !== 1) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** @return array<string, mixed> */
    private function metadata(TenantAiContext|TenantAiEmergencyContext $context, string $id): array
    {
        $row = TenantAiCredential::query()->useWritePdo()->select(['id', 'connection_id', 'state', 'verification_state', 'created_at', 'expires_at'])
            ->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->where('id', $id)->first();
        if ($row === null) {
            throw new TenantAiStorageUnavailable;
        }

        return $row->toArray();
    }

    private function audit(TenantAiContext|TenantAiEmergencyContext $context, string $event, string $outcome, string $operation,
        \stdClass $settings, ?\stdClass $connection, ?string $credentialId, int $settingsRevision, ?int $connectionRevision): void
    {
        $actor = $context->authorize();
        $metadata = ['actor_kind' => 'human', 'actor_attribution_id' => $actor->attribution_id, 'workspace_public_id' => $context->workspacePublicId,
            'connection_id' => $connection === null ? $settings->connection_id : $connection->id, 'credential_version_id' => $credentialId ?? $settings->credential_version_id,
            'profile_id' => $connection === null ? $settings->profile_id : $connection->binding_profile_id, 'operation_id' => $operation, 'outcome' => $outcome,
            'old_settings_revision' => (int) $settings->revision, 'new_settings_revision' => $settingsRevision,
            'old_connection_revision' => $connection === null ? null : (int) $connection->revision, 'new_connection_revision' => $connectionRevision,
            'user_agent' => null, 'ip_address' => null];
        RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event('assistant.ai.'.$event)->withProperties($metadata)->log('AI credential lifecycle changed'));
    }
}
