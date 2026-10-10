<?php

use App\Fiscal\IntegrationSchemaGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->scopes(true);
        Schema::create('external_command_capacity', function (Blueprint $table): void {
            $table->foreignId('integration_id')->primary()->constrained('integrations')->restrictOnDelete();
            $table->unsignedInteger('completed_count')->default(0);
        });
        Schema::create('external_command_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('integration_id')->constrained()->restrictOnDelete();
            $table->foreignId('origin_credential_id');
            $table->foreign(['origin_credential_id', 'integration_id'], 'command_origin_credential_fk')->references(['id', 'integration_id'])->on('integration_credentials')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreign(['legal_entity_id', 'workspace_id'], 'command_entity_workspace_fk')->references(['id', 'workspace_id'])->on('legal_entities')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('command', 64);
            $table->unsignedSmallInteger('capability_version');
            $table->string('canonicalizer_version', 32);
            $table->char('key_hash', 64);
            $table->char('fingerprint_hash', 64);
            $table->foreignId('origin_sponsor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('origin_sponsor_attribution_id');
            $table->string('state', 16);
            $table->ulid('result_public_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->unique(['integration_id', 'workspace_id', 'legal_entity_id', 'environment', 'command', 'capability_version', 'key_hash'], 'command_namespace_unique');
            $table->index(['integration_id', 'created_at'], 'command_integration_created_index');
        });
        IntegrationSchemaGuards::uuid('external_command_operations', 'operation_id');
        IntegrationSchemaGuards::uuid('external_command_operations', 'origin_sponsor_attribution_id');
        $this->guards();
    }

    public function down(): void
    {
        foreach (['integration_scopes', 'integration_credential_scopes'] as $table) {
            if (DB::table($table)->where('scope', 'customers:create')->exists()) {
                throw new RuntimeException('Command grant evidence exists; forward repair required.');
            }
        }
        if (DB::table('external_command_operations')->exists() || DB::table('external_command_capacity')->exists()
            || DB::table('activity_log')->where('event', 'like', 'external.command.%')->exists()) {
            throw new RuntimeException('Command evidence exists; forward repair required.');
        }
        Schema::drop('external_command_operations');
        Schema::drop('external_command_capacity');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['command_insert_guard', 'command_update_guard', 'command_delete_guard', 'command_complete_guard', 'command_capacity_guard', 'command_capacity_insert_guard', 'command_capacity_delete_guard', 'command_final_state_guard'] as $function) {
                DB::statement("DROP FUNCTION IF EXISTS $function()");
            }
        }
        $this->scopes(false);
    }

    private function scopes(bool $create): void
    {
        $scopes = "'documents:read','customers:read','catalogue:read','documents:agt-status:read','analytics:billing:read'".($create ? ",'customers:create'" : '');
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

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $hash = $pg ? "NEW.key_hash ~ '^[0-9a-f]{64}$' AND NEW.fingerprint_hash ~ '^[0-9a-f]{64}$'" : "length(NEW.key_hash)=64 AND NEW.key_hash NOT GLOB '*[^0-9a-f]*' AND length(NEW.fingerprint_hash)=64 AND NEW.fingerprint_hash NOT GLOB '*[^0-9a-f]*'";
        $insert = "NEW.state <> 'executing' OR NEW.environment <> 'production' OR NEW.command <> 'customers.create' OR NEW.capability_version <> 1 OR NEW.canonicalizer_version <> 'command-json-v1' OR NOT ($hash) OR NEW.completed_at IS NOT NULL OR NEW.result_public_id IS NOT NULL OR NEW.http_status IS NOT NULL OR NEW.response_body IS NOT NULL OR NOT EXISTS(SELECT 1 FROM integrations i WHERE i.id=NEW.integration_id AND i.workspace_id=NEW.workspace_id AND i.legal_entity_id=NEW.legal_entity_id AND i.environment=NEW.environment AND i.sponsor_user_id=NEW.origin_sponsor_user_id) OR NOT EXISTS(SELECT 1 FROM users u WHERE u.id=NEW.origin_sponsor_user_id AND u.attribution_id=NEW.origin_sponsor_attribution_id)";
        $this->reject('external_command_operations', 'command_insert_guard', 'INSERT', $insert);
        $columns = ['id', 'operation_id', 'integration_id', 'origin_credential_id', 'workspace_id', 'legal_entity_id', 'environment', 'command', 'capability_version', 'canonicalizer_version', 'key_hash', 'fingerprint_hash', 'origin_sponsor_attribution_id', 'created_at'];
        $different = fn (string $c): string => $pg ? "NEW.$c IS DISTINCT FROM OLD.$c" : "NEW.$c IS NOT OLD.$c";
        $immutable = implode(' OR ', array_map($different, $columns)).' OR (NEW.origin_sponsor_user_id IS NOT NULL AND '.$different('origin_sponsor_user_id').') OR (NEW.origin_sponsor_user_id IS NULL AND OLD.origin_sponsor_user_id IS NOT NULL AND EXISTS(SELECT 1 FROM users WHERE id=OLD.origin_sponsor_user_id))';
        $resultImmutable = implode(' OR ', array_map($different, ['state', 'result_public_id', 'http_status', 'response_body', 'completed_at']));
        $shape = $pg ? "NEW.result_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$' AND octet_length(NEW.response_body)<=1024 AND NEW.response_body::jsonb=jsonb_build_object('data',jsonb_build_object('public_id',NEW.result_public_id),'meta',jsonb_build_object('operation_id',NEW.operation_id::text))" : "length(NEW.result_public_id)=26 AND substr(NEW.result_public_id,1,1) GLOB '[0-7]' AND NEW.result_public_id NOT GLOB '*[^0-9a-hjkmnp-tv-z]*' AND length(CAST(NEW.response_body AS BLOB))<=1024 AND json_valid(NEW.response_body) AND json_extract(NEW.response_body,'$.data.public_id')=NEW.result_public_id AND json_extract(NEW.response_body,'$.meta.operation_id')=NEW.operation_id AND (SELECT count(*) FROM json_tree(NEW.response_body))=5";
        $update = "$immutable OR (OLD.state='succeeded' AND ($resultImmutable)) OR (OLD.state='executing' AND (NEW.state<>'succeeded' OR NEW.result_public_id IS NULL OR NEW.http_status IS NULL OR NEW.http_status<>201 OR NEW.response_body IS NULL OR NEW.completed_at IS NULL OR NEW.completed_at<NEW.created_at OR NOT ($shape)))";
        $this->reject('external_command_operations', 'command_update_guard', 'UPDATE', $update);
        $this->reject('external_command_operations', 'command_delete_guard', 'DELETE', '1=1');
        $this->reject('external_command_capacity', 'command_capacity_insert_guard', 'INSERT', 'NEW.completed_count<>0');
        $this->reject('external_command_capacity', 'command_capacity_guard', 'UPDATE', 'NEW.integration_id<>OLD.integration_id OR NEW.completed_count<>OLD.completed_count+1 OR NEW.completed_count>100000'.($pg ? ' OR pg_trigger_depth()<2' : ''));
        $this->reject('external_command_capacity', 'command_capacity_delete_guard', 'DELETE', '1=1');
        if ($pg) {
            DB::unprepared("CREATE OR REPLACE FUNCTION command_complete_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN UPDATE external_command_capacity SET completed_count=completed_count+1 WHERE integration_id=NEW.integration_id AND completed_count<100000; IF NOT FOUND THEN RAISE EXCEPTION 'Command capacity unavailable'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER command_complete_guard AFTER UPDATE ON external_command_operations FOR EACH ROW WHEN (OLD.state='executing' AND NEW.state='succeeded') EXECUTE FUNCTION command_complete_guard();
                CREATE OR REPLACE FUNCTION command_final_state_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF EXISTS(SELECT 1 FROM external_command_operations WHERE id=NEW.id AND state<>'succeeded') THEN RAISE EXCEPTION 'Unfinished command'; END IF; RETURN NULL; END; \$\$; CREATE CONSTRAINT TRIGGER command_final_state_guard AFTER INSERT OR UPDATE ON external_command_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION command_final_state_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER command_complete_guard AFTER UPDATE ON external_command_operations WHEN OLD.state='executing' AND NEW.state='succeeded' BEGIN UPDATE external_command_capacity SET completed_count=completed_count+1 WHERE integration_id=NEW.integration_id AND completed_count<100000; SELECT CASE WHEN changes()<>1 THEN RAISE(ABORT,'Command capacity unavailable') END; END;");
        }
    }

    private function reject(string $table, string $name, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Command integrity'; END IF; RETURN ".($event === 'DELETE' ? 'OLD' : 'NEW').'; END; $$;');
            DB::statement("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW EXECUTE FUNCTION $name();");
        } else {
            DB::statement("CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT,'Command integrity'); END");
        }
    }
};
