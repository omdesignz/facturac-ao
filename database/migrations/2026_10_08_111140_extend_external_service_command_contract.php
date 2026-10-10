<?php

use App\Fiscal\IntegrationSchemaGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->scopes(true);
        $this->insertGuard(true);
    }

    public function down(): void
    {
        foreach (['integration_scopes', 'integration_credential_scopes'] as $table) {
            if (DB::table($table)->where('scope', 'catalogue:services:create')->exists()) {
                throw new RuntimeException('Service grant evidence exists; forward repair required.');
            }
        }
        if (DB::table('external_command_operations')->where('command', 'catalogue.services.create')->exists()
            || DB::table('activity_log')->where('event', 'catalogue.service.created')->exists()
            || DB::table('activity_log')->where('properties->capability', 'catalogue.services.create')->exists()
            || DB::table('activity_log')->whereJsonContains('properties->scopes', 'catalogue:services:create')->exists()
            || DB::table('activity_log')->whereJsonContains('properties->previous_scopes', 'catalogue:services:create')->exists()) {
            throw new RuntimeException('Service evidence exists; forward repair required.');
        }
        $this->insertGuard(false);
        $this->scopes(false);
    }

    private function scopes(bool $service): void
    {
        $scopes = "'documents:read','customers:read','catalogue:read','documents:agt-status:read','analytics:billing:read','customers:create'".($service ? ",'catalogue:services:create'" : '');
        foreach (['integration_scopes' => ['integration_id', 'integrations'], 'integration_credential_scopes' => ['credential_id', 'integration_credentials']] as $table => [$key, $parent]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_scope_replacement CHECK (scope IN ($scopes))");
                DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_scope_check");
                DB::statement("ALTER TABLE $table RENAME CONSTRAINT {$table}_scope_replacement TO {$table}_scope_check");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::statement("CREATE TABLE {$table}_replacement ($key BIGINT NOT NULL REFERENCES $parent(id) ON DELETE RESTRICT, scope VARCHAR(32) NOT NULL CHECK (scope IN ($scopes)), PRIMARY KEY($key,scope))");
                DB::statement("INSERT INTO {$table}_replacement ($key,scope) SELECT $key,scope FROM $table");
                Schema::drop($table);
                DB::statement("ALTER TABLE {$table}_replacement RENAME TO $table");
                if ($table === 'integration_credential_scopes') {
                    IntegrationSchemaGuards::immutable($table, ['credential_id', 'scope']);
                }
            } else {
                throw new RuntimeException('Integration integrity requires PostgreSQL or SQLite.');
            }
        }
        if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('Command scope integrity failed.');
        }
    }

    private function insertGuard(bool $service): void
    {
        $commands = "'customers.create'".($service ? ",'catalogue.services.create'" : '');
        $pg = DB::getDriverName() === 'pgsql';
        $hash = $pg ? "NEW.key_hash ~ '^[0-9a-f]{64}$' AND NEW.fingerprint_hash ~ '^[0-9a-f]{64}$'" : "length(NEW.key_hash)=64 AND NEW.key_hash NOT GLOB '*[^0-9a-f]*' AND length(NEW.fingerprint_hash)=64 AND NEW.fingerprint_hash NOT GLOB '*[^0-9a-f]*'";
        $insert = "NEW.state <> 'executing' OR NEW.environment <> 'production' OR NEW.command NOT IN ($commands) OR NEW.capability_version <> 1 OR NEW.canonicalizer_version <> 'command-json-v1' OR NOT ($hash) OR NEW.completed_at IS NOT NULL OR NEW.result_public_id IS NOT NULL OR NEW.http_status IS NOT NULL OR NEW.response_body IS NOT NULL OR NOT EXISTS(SELECT 1 FROM integrations i WHERE i.id=NEW.integration_id AND i.workspace_id=NEW.workspace_id AND i.legal_entity_id=NEW.legal_entity_id AND i.environment=NEW.environment AND i.sponsor_user_id=NEW.origin_sponsor_user_id) OR NOT EXISTS(SELECT 1 FROM users u WHERE u.id=NEW.origin_sponsor_user_id AND u.attribution_id=NEW.origin_sponsor_attribution_id)";
        if ($pg) {
            DB::statement("CREATE OR REPLACE FUNCTION command_insert_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $insert THEN RAISE EXCEPTION 'Command integrity'; END IF; RETURN NEW; END; \$\$");
        } else {
            DB::statement('DROP TRIGGER command_insert_guard');
            DB::statement("CREATE TRIGGER command_insert_guard BEFORE INSERT ON external_command_operations WHEN $insert BEGIN SELECT RAISE(ABORT,'Command integrity'); END");
        }
    }
};
