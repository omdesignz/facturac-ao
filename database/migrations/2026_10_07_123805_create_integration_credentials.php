<?php

use App\Fiscal\IntegrationSchemaGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $id = DB::getDriverName() === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $uuid = DB::getDriverName() === 'pgsql' ? 'UUID' : 'VARCHAR(36)';
        $hashShape = DB::getDriverName() === 'pgsql' ? "secret_hash ~ '^[0-9a-f]{64}$'" : "length(secret_hash) = 64 AND secret_hash NOT GLOB '*[^0-9a-f]*'";
        $reasons = "'owner_request','rotation','compromise','authority_withdrawn','setup_failure'";
        DB::statement("CREATE TABLE integrations (
            id $id, public_id VARCHAR(26) NOT NULL UNIQUE, workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
            legal_entity_id BIGINT NOT NULL, environment VARCHAR(24) NOT NULL CHECK (environment IN ('homologation','production')),
            name VARCHAR(100) NOT NULL, sponsor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            sponsor_membership_id BIGINT REFERENCES workspace_memberships(id) ON DELETE SET NULL,
            creator_principal_kind VARCHAR(20) NOT NULL CHECK (creator_principal_kind = 'user'), creator_attribution_id $uuid NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), revoked_at TIMESTAMP NULL,
            revoked_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL, revocation_reason VARCHAR(32) NULL CHECK (revocation_reason IN ($reasons)),
            created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NULL,
            FOREIGN KEY (legal_entity_id,workspace_id) REFERENCES legal_entities(id,workspace_id) ON DELETE RESTRICT)");
        DB::statement("CREATE TABLE integration_scopes (integration_id BIGINT NOT NULL REFERENCES integrations(id) ON DELETE RESTRICT,
            scope VARCHAR(32) NOT NULL CHECK (scope = 'documents:read'), PRIMARY KEY(integration_id,scope))");
        DB::statement("CREATE TABLE integration_credentials (
            id $id, public_id VARCHAR(26) NOT NULL UNIQUE, integration_id BIGINT NOT NULL REFERENCES integrations(id) ON DELETE RESTRICT,
            secret_hash VARCHAR(64) NOT NULL UNIQUE CHECK ($hashShape), hash_version VARCHAR(20) NOT NULL CHECK (hash_version = 'sha256-v1'),
            created_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            creator_principal_kind VARCHAR(20) NOT NULL CHECK (creator_principal_kind = 'user'), creator_attribution_id $uuid NOT NULL,
            expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP NULL, revoked_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            revocation_reason VARCHAR(32) NULL CHECK (revocation_reason IN ($reasons)), replaces_credential_id BIGINT NULL,
            last_used_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NULL,
            CHECK (expires_at > created_at), UNIQUE(id,integration_id),
            FOREIGN KEY (replaces_credential_id,integration_id) REFERENCES integration_credentials(id,integration_id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX integration_credentials_live ON integration_credentials(integration_id,revoked_at,expires_at)');
        DB::statement("CREATE TABLE integration_credential_scopes (credential_id BIGINT NOT NULL REFERENCES integration_credentials(id) ON DELETE RESTRICT,
            scope VARCHAR(32) NOT NULL CHECK (scope = 'documents:read'), PRIMARY KEY(credential_id,scope))");
        IntegrationSchemaGuards::immutable('integrations', ['public_id', 'workspace_id', 'legal_entity_id', 'environment', 'creator_principal_kind', 'creator_attribution_id'], ['sponsor_user_id', 'sponsor_membership_id'], terminal: true);
        IntegrationSchemaGuards::immutable('integration_credentials', ['public_id', 'integration_id', 'secret_hash', 'hash_version', 'creator_principal_kind', 'creator_attribution_id', 'replaces_credential_id'], ['created_by_user_id'], terminal: true);
        IntegrationSchemaGuards::immutable('integration_credential_scopes', ['credential_id', 'scope']);
        IntegrationSchemaGuards::uuid('integrations', 'creator_attribution_id');
        IntegrationSchemaGuards::uuid('integration_credentials', 'creator_attribution_id');
    }

    public function down(): void
    {
        if (DB::table('integrations')->exists() || DB::table('integration_credentials')->exists()) {
            throw new RuntimeException('Integration evidence exists; forward repair required.');
        }
        foreach (['integration_credential_scopes', 'integration_credentials', 'integration_scopes', 'integrations'] as $table) {
            if ($table !== 'integration_scopes') {
                IntegrationSchemaGuards::drop($table);
            }
            Schema::dropIfExists($table);
        }
    }
};
