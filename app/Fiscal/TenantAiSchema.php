<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\DB;

/** PostgreSQL integrity with fast SQLite equivalents; no provider execution. */
final class TenantAiSchema
{
    public static function create(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        if (! $pg && DB::getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Tenant AI storage requires PostgreSQL or SQLite.');
        }
        $uuid = $pg ? 'uuid' : 'text';
        $time = $pg ? 'timestamptz' : 'text';
        $identity = "creator_attribution_id $uuid NOT NULL, creator_user_id bigint REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT, created_at $time NOT NULL, updated_at $time NOT NULL";
        self::table('ai_model_profiles', "id $uuid NOT NULL PRIMARY KEY, profile_key varchar(80) NOT NULL UNIQUE, manifest_sha256 char(64) NOT NULL UNIQUE,
            provider_key varchar(80) NOT NULL, credential_family varchar(80) NOT NULL, endpoint_policy_key varchar(80) NOT NULL,
            model_key varchar(120) NOT NULL, response_model_key varchar(120) NOT NULL, protocol_key varchar(80) NOT NULL,
            price_key varchar(80) NOT NULL, privacy_key varchar(80) NOT NULL, disclosure_key varchar(80) NOT NULL, input_policy_key varchar(80) NOT NULL,
            purpose varchar(24) NOT NULL CHECK(purpose='assistant_intent'), allows_vap boolean NOT NULL DEFAULT false, allows_customer boolean NOT NULL DEFAULT false,
            monetary_applicable boolean NOT NULL, currency char(3), input_envelope bigint NOT NULL CHECK(input_envelope>0),
            output_envelope bigint NOT NULL CHECK(output_envelope>0), request_byte_limit bigint NOT NULL CHECK(request_byte_limit>0),
            response_byte_limit bigint NOT NULL CHECK(response_byte_limit>0), deadline_ms bigint NOT NULL CHECK(deadline_ms>0),
            reservation_micro_usd bigint, valid_from $time NOT NULL, valid_until $time NOT NULL, created_at $time NOT NULL,
            UNIQUE(id,provider_key,credential_family,endpoint_policy_key), CHECK(allows_vap OR allows_customer), CHECK(valid_until>valid_from),
            CHECK((monetary_applicable AND currency IS NOT NULL AND currency='USD' AND reservation_micro_usd IS NOT NULL AND reservation_micro_usd>0)
                OR (NOT monetary_applicable AND currency IS NULL AND reservation_micro_usd IS NULL))");
        self::table('ai_gateway_controls', "id $uuid NOT NULL PRIMARY KEY, deployment_id $uuid NOT NULL, kind varchar(16) NOT NULL CHECK(kind IN ('global','provider','model')),
            subject_key varchar(80) NOT NULL, profile_id $uuid REFERENCES ai_model_profiles(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            revision bigint NOT NULL DEFAULT 1 CHECK(revision>0), enabled boolean NOT NULL DEFAULT false, circuit_blocked boolean NOT NULL DEFAULT true,
            approval_reference varchar(80), approval_expires_at $time, outcome varchar(40) CHECK(outcome IN ('disabled','pending','approved','expired','revoked','anomaly')),
            created_at $time NOT NULL, updated_at $time NOT NULL, UNIQUE(deployment_id,kind,subject_key), UNIQUE(id,deployment_id),
            CHECK(NOT enabled OR (approval_reference IS NOT NULL AND approval_expires_at IS NOT NULL AND NOT circuit_blocked)),
            CHECK((kind='global' AND subject_key='root' AND profile_id IS NULL) OR (kind='provider' AND profile_id IS NULL) OR (kind='model' AND profile_id IS NOT NULL))");
        $flags = implode(',', array_map(fn (string $c): string => "$c boolean NOT NULL DEFAULT false", ['customer_search_enabled', 'customer_detail_enabled', 'documents_enabled', 'agt_enabled', 'billing_enabled']));
        $caps = implode(',', array_map(fn (string $c): string => "$c bigint NOT NULL DEFAULT 0 CHECK($c>=0)", ['attempts_day_cap', 'attempts_month_cap', 'output_day_cap', 'output_month_cap', 'money_day_micro_usd_cap', 'money_month_micro_usd_cap', 'entitlement_revision']));
        $selectionForeign = 'CONSTRAINT tai_selection_connection_fk FOREIGN KEY(connection_id,workspace_id,deployment_id) REFERENCES tenant_ai_connections(id,workspace_id,deployment_id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED,
            CONSTRAINT tai_selection_version_fk FOREIGN KEY(credential_version_id,connection_id,workspace_id,deployment_id) REFERENCES tenant_ai_credentials(id,connection_id,workspace_id,deployment_id) ON DELETE RESTRICT ON UPDATE RESTRICT DEFERRABLE INITIALLY DEFERRED';
        self::table('tenant_ai_settings', "workspace_id bigint NOT NULL PRIMARY KEY REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE RESTRICT, deployment_id $uuid NOT NULL,
            root_control_id $uuid NOT NULL, mode varchar(24) NOT NULL DEFAULT 'disabled' CHECK(mode IN ('disabled','vap_managed','customer_managed')),
            revision bigint NOT NULL DEFAULT 1 CHECK(revision>0), profile_id $uuid REFERENCES ai_model_profiles(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            connection_id $uuid, credential_version_id $uuid, $caps, $flags, $identity, UNIQUE(workspace_id,deployment_id),
            FOREIGN KEY(root_control_id,deployment_id) REFERENCES ai_gateway_controls(id,deployment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CHECK((mode='disabled' AND profile_id IS NULL AND connection_id IS NULL AND credential_version_id IS NULL)
                OR (mode='vap_managed' AND profile_id IS NOT NULL AND connection_id IS NULL AND credential_version_id IS NULL)
                OR (mode='customer_managed' AND profile_id IS NOT NULL AND connection_id IS NOT NULL))".($pg ? '' : ",$selectionForeign"));
        self::table('tenant_ai_connections', "id $uuid NOT NULL PRIMARY KEY, workspace_id bigint NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            deployment_id $uuid NOT NULL, ownership_kind varchar(24) NOT NULL DEFAULT 'customer_managed' CHECK(ownership_kind='customer_managed'),
            binding_profile_id $uuid NOT NULL, provider_key varchar(80) NOT NULL, credential_family varchar(80) NOT NULL, endpoint_policy_key varchar(80) NOT NULL,
            payer_budget_id $uuid REFERENCES assistant_provider_controls(budget_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            revision bigint NOT NULL DEFAULT 1 CHECK(revision>0), disabled boolean NOT NULL DEFAULT true, revoked_at $time, $identity,
            UNIQUE(id,workspace_id,deployment_id), FOREIGN KEY(workspace_id,deployment_id) REFERENCES tenant_ai_settings(workspace_id,deployment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY(binding_profile_id,provider_key,credential_family,endpoint_policy_key) REFERENCES ai_model_profiles(id,provider_key,credential_family,endpoint_policy_key) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CHECK(payer_budget_id IS NULL), CHECK(revoked_at IS NULL OR disabled)");
        self::table('tenant_ai_credentials', "id $uuid NOT NULL PRIMARY KEY, connection_id $uuid NOT NULL, workspace_id bigint NOT NULL, deployment_id $uuid NOT NULL,
            version_number bigint NOT NULL CHECK(version_number>0), state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','active','replaced','revoked')),
            verification_state varchar(16) NOT NULL DEFAULT 'unverified' CHECK(verification_state IN ('unverified','verified','failed')),
            secret_ciphertext text, wrapped_dek text, encryption_schema smallint NOT NULL DEFAULT 1 CHECK(encryption_schema=1), kek_version varchar(80),
            wrap_revision bigint NOT NULL DEFAULT 1 CHECK(wrap_revision>0), expires_at $time, replaced_at $time, revoked_at $time,
            secret_destroyed_at $time, last_used_at $time, last_tested_at $time, last_verified_at $time,
            last_test_outcome varchar(24) CHECK(last_test_outcome IN ('success','auth_failed','protocol_failed','unavailable','unknown')),
            verified_profile_id $uuid REFERENCES ai_model_profiles(id) ON DELETE RESTRICT ON UPDATE RESTRICT, verification_operation_id $uuid UNIQUE, $identity,
            FOREIGN KEY(connection_id,workspace_id,deployment_id) REFERENCES tenant_ai_connections(id,workspace_id,deployment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            UNIQUE(connection_id,version_number), UNIQUE(id,connection_id,workspace_id,deployment_id), UNIQUE(workspace_id,id),
            CHECK((secret_destroyed_at IS NULL AND secret_ciphertext IS NOT NULL AND wrapped_dek IS NOT NULL AND kek_version IS NOT NULL)
                OR (secret_destroyed_at IS NOT NULL AND secret_ciphertext IS NULL AND wrapped_dek IS NULL AND kek_version IS NULL AND state IN ('replaced','revoked'))),
            CHECK((state IN ('pending','active') AND replaced_at IS NULL AND revoked_at IS NULL) OR (state='replaced' AND replaced_at IS NOT NULL AND revoked_at IS NOT NULL) OR (state='revoked' AND revoked_at IS NOT NULL)),
            CHECK(state<>'active' OR (verification_state='verified' AND verified_profile_id IS NOT NULL AND verification_operation_id IS NOT NULL AND last_verified_at IS NOT NULL AND secret_destroyed_at IS NULL)),
            CHECK(expires_at IS NULL OR expires_at>created_at)");
        if ($pg) {
            foreach (explode(",\n            ", $selectionForeign) as $fk) {
                DB::statement('ALTER TABLE tenant_ai_settings ADD '.$fk);
            }
        }
        self::indexes();
        self::guards();
    }

    private static function table(string $name, string $columns): void
    {
        DB::statement("CREATE TABLE $name ($columns)");
        preg_match_all('/(?:^|,)\s*([a-z_]+) (uuid|varchar\((\d+)\)|char\((\d+)\)|boolean)(?=\s|,)/', $columns, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $c = $m[1];
            if (str_starts_with($m[2], 'varchar') || str_starts_with($m[2], 'char')) {
                $length = (int) (($m[3] ?? '') ?: ($m[4] ?? '0'));
                self::reject($name, "length(NEW.$c)>$length", 'length_'.$c);
            } elseif ($m[2] === 'boolean') {
                self::reject($name, "NEW.$c NOT IN (true,false)", 'boolean_'.$c);
            }
        }
    }

    private static function indexes(): void
    {
        $indexes = ['ai_gateway_controls' => ['profile_id'], 'tenant_ai_settings' => ['root_control_id,deployment_id', 'profile_id', 'connection_id,workspace_id,deployment_id', 'credential_version_id,connection_id,workspace_id,deployment_id', 'creator_user_id'],
            'tenant_ai_connections' => ['workspace_id,deployment_id,revoked_at,id', 'binding_profile_id,provider_key,credential_family,endpoint_policy_key', 'payer_budget_id', 'creator_user_id'],
            'tenant_ai_credentials' => ['connection_id,workspace_id,deployment_id', 'workspace_id,deployment_id,state,expires_at,id', 'verified_profile_id', 'creator_user_id']];
        foreach ($indexes as $table => $columns) {
            foreach ($columns as $i => $list) {
                DB::statement("CREATE INDEX {$table}_lookup_$i ON $table ($list)");
            }
        }
        foreach (['pending', 'active'] as $state) {
            DB::statement("CREATE UNIQUE INDEX tenant_ai_credentials_one_$state ON tenant_ai_credentials(connection_id) WHERE state='$state'");
        }
    }

    private static function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        foreach (['ai_model_profiles', 'ai_gateway_controls', 'tenant_ai_settings', 'tenant_ai_connections', 'tenant_ai_credentials'] as $table) {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);
            foreach ($columns as $c) {
                if (in_array($c, ['id', 'deployment_id', 'profile_id', 'root_control_id', 'binding_profile_id', 'connection_id', 'credential_version_id', 'payer_budget_id', 'verified_profile_id', 'verification_operation_id', 'creator_attribution_id'], true) && ! ($table === 'tenant_ai_settings' && $c === 'id')) {
                    $valid = $pg ? "NEW.$c::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'"
                        : "length(NEW.$c)=36 AND substr(NEW.$c,9,1)='-' AND substr(NEW.$c,14,1)='-' AND substr(NEW.$c,19,1)='-' AND substr(NEW.$c,24,1)='-' AND substr(NEW.$c,15,1)='4' AND substr(NEW.$c,20,1) IN ('8','9','a','b') AND length(replace(NEW.$c,'-',''))=32 AND replace(NEW.$c,'-','') NOT GLOB '*[^0-9a-f]*'";
                    self::reject($table, "NEW.$c IS NOT NULL AND NOT ($valid)", 'uuid_'.$c);
                }
            }
            self::reject($table, 'NEW.updated_at < OLD.updated_at', 'time', true, skip: $table === 'ai_model_profiles');
        }
        $profileColumns = DB::getSchemaBuilder()->getColumnListing('ai_model_profiles');
        self::immutable('ai_model_profiles', $profileColumns);
        self::immutable('ai_gateway_controls', ['id', 'deployment_id', 'kind', 'subject_key', 'profile_id', 'created_at']);
        self::immutable('tenant_ai_settings', ['workspace_id', 'deployment_id', 'root_control_id', 'creator_attribution_id', 'created_at'], true);
        self::immutable('tenant_ai_connections', ['id', 'workspace_id', 'deployment_id', 'ownership_kind', 'binding_profile_id', 'provider_key', 'credential_family', 'endpoint_policy_key', 'creator_attribution_id', 'created_at'], true);
        self::immutable('tenant_ai_credentials', ['id', 'connection_id', 'workspace_id', 'deployment_id', 'version_number', 'encryption_schema', 'creator_attribution_id', 'created_at'], true);
        foreach (['ai_gateway_controls', 'tenant_ai_settings', 'tenant_ai_connections'] as $table) {
            $exception = $table === 'ai_gateway_controls' ? 'false' : '(OLD.creator_user_id IS NOT NULL AND NEW.creator_user_id IS NULL AND NEW.revision=OLD.revision)';
            if ($table !== 'ai_gateway_controls') {
                $unchanged = array_filter(DB::getSchemaBuilder()->getColumnListing($table), fn (string $c): bool => $c !== 'creator_user_id');
                $exception .= ' AND '.implode(' AND ', array_map(fn (string $c): string => "NEW.$c IS NOT DISTINCT FROM OLD.$c", $unchanged));
            }
            self::reject($table, "NOT (NEW.revision=OLD.revision+1 OR ($exception))", 'revision', true);
        }
        self::reject('ai_gateway_controls', "(NEW.kind='provider' AND NEW.subject_key<>'anthropic') OR (NEW.kind='model' AND NOT EXISTS (SELECT 1 FROM ai_model_profiles p WHERE p.id=NEW.profile_id AND p.profile_key=NEW.subject_key))", 'subject');
        self::reject('tenant_ai_settings', "NOT EXISTS (SELECT 1 FROM ai_gateway_controls c WHERE c.id=NEW.root_control_id AND c.deployment_id=NEW.deployment_id AND c.kind='global')", 'root');
        self::reject('tenant_ai_connections', 'OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS DISTINCT FROM OLD.revoked_at OR NOT NEW.disabled)', 'terminal', true);
        self::reject('tenant_ai_credentials', 'NOT EXISTS (SELECT 1 FROM tenant_ai_connections c WHERE c.id=NEW.connection_id AND c.workspace_id=NEW.workspace_id AND c.deployment_id=NEW.deployment_id AND c.revoked_at IS NULL)', 'connection', insertOnly: true);
        self::reject('tenant_ai_credentials', "NEW.state<>'pending' OR NEW.verification_state<>'unverified' OR NEW.last_used_at IS NOT NULL OR NEW.last_tested_at IS NOT NULL OR NEW.last_verified_at IS NOT NULL OR NEW.verified_profile_id IS NOT NULL OR NEW.verification_operation_id IS NOT NULL OR NEW.last_test_outcome IS NOT NULL OR NEW.secret_destroyed_at IS NOT NULL", 'initial', insertOnly: true);
        self::reject('tenant_ai_credentials', "NEW.verification_state<>'unverified' OR NEW.verified_profile_id IS NOT NULL OR NEW.verification_operation_id IS NOT NULL OR NEW.last_used_at IS NOT NULL OR NEW.last_tested_at IS NOT NULL OR NEW.last_verified_at IS NOT NULL OR NEW.last_test_outcome IS NOT NULL", 'no_probe');
        self::reject('tenant_ai_credentials', "NOT (NEW.state=OLD.state OR (OLD.state='pending' AND NEW.state IN ('active','revoked')) OR (OLD.state='active' AND NEW.state IN ('replaced','revoked')))", 'transition', true);
        self::reject('tenant_ai_credentials', '(NEW.secret_ciphertext IS DISTINCT FROM OLD.secret_ciphertext AND NOT (NEW.secret_ciphertext IS NULL AND NEW.secret_destroyed_at IS NOT NULL)) OR (OLD.secret_destroyed_at IS NOT NULL AND NEW.secret_destroyed_at IS DISTINCT FROM OLD.secret_destroyed_at)', 'ciphertext', true);
        self::reject('tenant_ai_credentials', '(NEW.wrapped_dek IS DISTINCT FROM OLD.wrapped_dek OR NEW.kek_version IS DISTINCT FROM OLD.kek_version) AND NOT ((NEW.wrap_revision=OLD.wrap_revision+1 AND OLD.secret_destroyed_at IS NULL AND NEW.secret_destroyed_at IS NULL) OR (NEW.secret_destroyed_at IS NOT NULL AND NEW.wrapped_dek IS NULL AND NEW.kek_version IS NULL AND NEW.wrap_revision=OLD.wrap_revision)) OR (NEW.wrapped_dek IS NOT DISTINCT FROM OLD.wrapped_dek AND NEW.kek_version IS NOT DISTINCT FROM OLD.kek_version AND NEW.wrap_revision<>OLD.wrap_revision)', 'wrap', true);
        foreach (['replaced_at', 'revoked_at', 'secret_destroyed_at', 'last_used_at', 'last_tested_at', 'last_verified_at'] as $c) {
            self::reject('tenant_ai_credentials', "NEW.$c IS NOT NULL AND NEW.$c<NEW.created_at", 'date_'.$c);
            self::reject('tenant_ai_credentials', "OLD.$c IS NOT NULL AND (NEW.$c IS NULL OR NEW.$c<OLD.$c)", 'monotonic_'.$c, true);
        }
        foreach (['secret_ciphertext', 'wrapped_dek'] as $c) {
            $ascii = $pg ? "NEW.$c ~ '^[ -~]+$'" : "NEW.$c NOT GLOB '*[^ -~]*'";
            self::reject('tenant_ai_credentials', "NEW.$c IS NOT NULL AND (length(NEW.$c) NOT BETWEEN 1 AND 8192 OR NOT ($ascii))", 'envelope_'.$c);
        }
        foreach (['ai_gateway_controls' => 'approval_reference', 'tenant_ai_credentials' => 'kek_version'] as $table => $c) {
            $valid = $pg ? "NEW.$c ~ '^[A-Za-z0-9_-]{1,80}$'" : "length(NEW.$c) BETWEEN 1 AND 80 AND NEW.$c NOT GLOB '*[^A-Za-z0-9_-]*'";
            self::reject($table, "NEW.$c IS NOT NULL AND NOT ($valid)", 'reference');
        }
        $hex = $pg ? "NEW.manifest_sha256 ~ '^[0-9a-f]{64}$'" : "length(NEW.manifest_sha256)=64 AND NEW.manifest_sha256 NOT GLOB '*[^0-9a-f]*'";
        self::reject('ai_model_profiles', "NOT ($hex)", 'digest');
        foreach (['tenant_ai_credentials', 'tenant_ai_connections', 'tenant_ai_settings'] as $table) {
            $name = $table.'_retention';
            if ($pg) {
                DB::statement("CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Retained tenant AI evidence'; END; \$\$");
                DB::statement("CREATE TRIGGER $name BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
            } else {
                DB::statement("CREATE TRIGGER $name BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'Retained tenant AI evidence'); END");
            }
        }
        self::selectionGuard();
    }

    /** @param list<string> $columns */
    private static function immutable(string $table, array $columns, bool $creator = false): void
    {
        $condition = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $columns));
        if ($creator) {
            $condition .= ' OR (NEW.creator_user_id IS NOT NULL AND NEW.creator_user_id IS DISTINCT FROM OLD.creator_user_id)';
        }
        self::reject($table, $condition, 'immutable', true);
    }

    private static function reject(string $table, string $condition, string $suffix, bool $updateOnly = false, bool $insertOnly = false, bool $skip = false): void
    {
        if ($skip) {
            return;
        }
        $name = 'tai_'.substr(hash('sha256', $table.$suffix), 0, 20);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Invalid tenant AI state'; END IF; RETURN NEW; END; \$\$");
            $events = $updateOnly ? 'UPDATE' : ($insertOnly ? 'INSERT' : 'INSERT OR UPDATE');
            DB::statement("CREATE TRIGGER $name BEFORE $events ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
        } else {
            foreach ($updateOnly ? ['UPDATE'] : ($insertOnly ? ['INSERT'] : ['INSERT', 'UPDATE']) as $event) {
                $condition = str_replace([' IS NOT DISTINCT FROM ', ' IS DISTINCT FROM '], [' IS ', ' IS NOT '], $condition);
                DB::statement("CREATE TRIGGER {$name}_$event BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT,'Invalid tenant AI state'); END");
            }
        }
    }

    private static function selectionGuard(): void
    {
        $condition = "EXISTS(SELECT 1 FROM tenant_ai_settings s LEFT JOIN ai_model_profiles p ON p.id=s.profile_id
            LEFT JOIN tenant_ai_connections c ON c.id=s.connection_id AND c.workspace_id=s.workspace_id AND c.deployment_id=s.deployment_id
            LEFT JOIN tenant_ai_credentials v ON v.id=s.credential_version_id AND v.connection_id=c.id AND v.workspace_id=s.workspace_id AND v.deployment_id=s.deployment_id
            WHERE s.workspace_id=NEW.workspace_id AND ((s.mode='vap_managed' AND NOT p.allows_vap)
            OR (s.mode='customer_managed' AND (NOT p.allows_customer OR c.id IS NULL OR c.revoked_at IS NOT NULL OR p.provider_key<>c.provider_key OR p.credential_family<>c.credential_family OR p.endpoint_policy_key<>c.endpoint_policy_key))
            OR (s.credential_version_id IS NOT NULL AND (v.id IS NULL OR v.state<>'active' OR v.secret_destroyed_at IS NOT NULL))))";
        foreach (['tenant_ai_settings', 'tenant_ai_credentials', 'tenant_ai_connections'] as $table) {
            $name = $table.'_selection';
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Invalid tenant AI selection'; END IF; RETURN NEW; END; \$\$");
                DB::statement("CREATE CONSTRAINT TRIGGER $name AFTER INSERT OR UPDATE ON $table DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION $name()");
            } else {
                foreach (['INSERT', 'UPDATE'] as $event) {
                    DB::statement("CREATE TRIGGER {$name}_$event AFTER $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT,'Invalid tenant AI selection'); END");
                }
            }
        }
    }

    public static function drop(): void
    {
        foreach (['tenant_ai_credentials', 'tenant_ai_connections', 'tenant_ai_settings'] as $table) {
            if (DB::table($table)->exists()) {
                throw new \RuntimeException('Retained tenant AI evidence prevents rollback.');
            }
        }
        if (DB::table('activity_log')->where('event', 'like', 'assistant.ai.%')->exists()
            || DB::table('ai_gateway_controls')->whereNotNull('approval_reference')->exists()) {
            throw new \RuntimeException('Retained tenant AI audit prevents rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenant_ai_settings DROP CONSTRAINT tai_selection_connection_fk');
            DB::statement('ALTER TABLE tenant_ai_settings DROP CONSTRAINT tai_selection_version_fk');
        }
        foreach (['tenant_ai_credentials', 'tenant_ai_connections', 'tenant_ai_settings', 'ai_gateway_controls', 'ai_model_profiles'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                $functions = DB::select('SELECT DISTINCT p.proname FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid WHERE t.tgrelid=?::regclass AND NOT t.tgisinternal', [$table]);
                DB::statement("DROP TABLE $table");
                foreach ($functions as $function) {
                    DB::statement('DROP FUNCTION '.$function->proname.'()');
                }
            } else {
                DB::statement("DROP TABLE $table");
            }
        }
    }
}
