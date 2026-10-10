<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\TenantAiCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TenantAiStorage
{
    public function __construct(private TenantAiKeyFile $keys) {}

    /** @return array<string, mixed> */
    public function configure(TenantAiContext $context, #[\SensitiveParameter] string $secret, ?string $connectionId = null, int $expectedRevision = 1): array
    {
        try {
            $context->authorize();

            return DB::transaction(function () use ($context, $secret, $connectionId, $expectedRevision): array {
                $root = DB::table('ai_gateway_controls')->where('deployment_id', $context->deploymentId)->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->first();
                if ($root === null) {
                    throw new TenantAiStorageUnavailable;
                }
                $actor = $context->authorize();
                $time = now()->utc()->toIso8601String();
                $settings = DB::table('tenant_ai_settings')->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
                if ($settings === null) {
                    DB::table('tenant_ai_settings')->insert(['workspace_id' => $context->workspaceId, 'deployment_id' => $context->deploymentId,
                        'root_control_id' => $root->id, 'creator_attribution_id' => $actor->attribution_id, 'creator_user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                } elseif ($settings->deployment_id !== $context->deploymentId || $settings->root_control_id !== $root->id) {
                    throw new TenantAiStorageUnavailable;
                }
                $profile = TenantAiCatalogue::profile();
                $actual = DB::table('ai_model_profiles')->where('id', TenantAiCatalogue::ID)->first();
                if ($actual === null || $actual->manifest_sha256 !== $profile['manifest_sha256']) {
                    throw new TenantAiStorageUnavailable;
                }
                if ($connectionId === null) {
                    $connectionId = (string) Str::uuid();
                    DB::table('tenant_ai_connections')->insert(['id' => $connectionId, 'workspace_id' => $context->workspaceId, 'deployment_id' => $context->deploymentId,
                        'binding_profile_id' => $profile['id'], 'provider_key' => $profile['provider_key'], 'credential_family' => $profile['credential_family'], 'endpoint_policy_key' => $profile['endpoint_policy_key'],
                        'creator_attribution_id' => $actor->attribution_id, 'creator_user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                }
                $connection = DB::table('tenant_ai_connections')->where('id', $connectionId)->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->lockForUpdate()->first();
                if ($connection === null || $connection->revoked_at !== null || $connection->ownership_kind !== 'customer_managed'
                    || (int) $connection->revision !== $expectedRevision || $connection->binding_profile_id !== $profile['id']) {
                    throw new TenantAiStorageUnavailable;
                }
                if (DB::table('tenant_ai_credentials')->where('connection_id', $connection->id)->exists()) {
                    throw new TenantAiStorageUnavailable;
                }
                $versionId = (string) Str::uuid();
                $binding = $this->binding($context, $connection, $versionId);
                $envelope = TenantAiEnvelope::seal($secret, $binding, $this->keys);
                $context->authorize();
                $envelope->insert($context, ['id' => $versionId, 'connection_id' => $connectionId, 'workspace_id' => $context->workspaceId,
                    'deployment_id' => $context->deploymentId, 'version_number' => 1, 'creator_attribution_id' => $actor->attribution_id,
                    'creator_user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time]);
                $metadata = ['actor_kind' => 'human', 'actor_attribution_id' => $actor->attribution_id, 'workspace_public_id' => $context->workspacePublicId,
                    'connection_id' => $connectionId, 'credential_version_id' => $versionId, 'profile_id' => $profile['id'],
                    'operation_id' => (string) Str::uuid(), 'outcome' => 'pending', 'user_agent' => null, 'ip_address' => null];
                RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event('assistant.ai.credential_configured')->withProperties($metadata)->log('AI credential configured'));
                $context->authorize();

                return $this->metadata($context, $versionId);
            }, 1);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** @return array<string, mixed> */
    public function metadata(TenantAiContext $context, string $versionId): array
    {
        try {
            $context->authorize();
            $row = TenantAiCredential::query()->useWritePdo()->select(['id', 'connection_id', 'state', 'verification_state', 'created_at', 'expires_at'])
                ->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->where('id', $versionId)->first();
            if ($row === null) {
                throw new TenantAiStorageUnavailable;
            }

            return $row->toArray();
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    public function inspectForStorageTest(TenantAiContext $context, string $versionId, #[\SensitiveParameter] \Closure $assert): void
    {
        try {
            if (! app()->runningUnitTests() || ! app()->environment('testing') || PHP_SAPI !== 'cli') {
                throw new TenantAiStorageUnavailable;
            }
            $context->authorize();
            $statement = DB::connection()->getPdo()->prepare('SELECT * FROM tenant_ai_credentials WHERE id=? AND workspace_id=? AND deployment_id=?');
            $statement->execute([$versionId, $context->workspaceId, $context->deploymentId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if (! is_array($row)) {
                throw new TenantAiStorageUnavailable;
            }
            $connection = DB::table('tenant_ai_connections')->where('id', $row['connection_id'])->where('workspace_id', $context->workspaceId)->where('deployment_id', $context->deploymentId)->first();
            if ($connection === null) {
                throw new TenantAiStorageUnavailable;
            }
            TenantAiEnvelope::inspectForStorageTest($context, $row, $this->binding($context, $connection, $versionId), $this->keys, $assert);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** @return array<string, int|string> */
    private function binding(TenantAiContext $context, \stdClass $connection, string $versionId): array
    {
        return ['schema' => 1, 'deployment_id' => $context->deploymentId, 'workspace_public_id' => $context->workspacePublicId,
            'connection_id' => $connection->id, 'version_id' => $versionId, 'provider' => $connection->provider_key,
            'credential_family' => $connection->credential_family, 'endpoint_policy' => $connection->endpoint_policy_key];
    }
}
