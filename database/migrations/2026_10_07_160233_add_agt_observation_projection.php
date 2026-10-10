<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('AGT observation integrity requires PostgreSQL or SQLite.');
        }
        if (DB::table('agt_submissions')->exists() && ! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('Pause all fiscal writers in maintenance before changing AGT evidence schema.');
        }
        $invalid = DB::table('agt_submissions as s')->join('fiscal_documents as d', 'd.id', '=', 's.fiscal_document_id')
            ->join('agt_connections as c', 'c.id', '=', 's.agt_connection_id')->where(function ($query): void {
                $query->whereColumn('s.workspace_id', '!=', 'd.workspace_id')->orWhereColumn('s.legal_entity_id', '!=', 'd.legal_entity_id')
                    ->orWhereColumn('s.workspace_id', '!=', 'c.workspace_id')->orWhereColumn('s.legal_entity_id', '!=', 'c.legal_entity_id')
                    ->orWhereColumn('s.environment', '!=', 'd.environment')
                    ->orWhere(fn ($known) => $known->where('s.environment', '!=', 'unresolved')->whereColumn('s.environment', '!=', 'c.environment'));
            })->exists();
        if ($invalid) {
            throw new RuntimeException('AGT identity conflict requires reconciliation before schema mutation.');
        }
        Schema::table('agt_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('operation_sequence')->default(0);
            $table->uuid('active_operation_uuid')->nullable();
            $table->timestampTz('operation_lease_expires_at')->nullable();
            $table->unsignedBigInteger('projection_revision')->default(0);
            $table->unsignedSmallInteger('reducer_version')->default(1);
            $table->json('qualified_projection')->nullable();
        });
        Schema::table('agt_submission_attempts', function (Blueprint $table): void {
            $table->unique(['id', 'agt_submission_id', 'workspace_id', 'legal_entity_id'], 'agt_attempt_observation_identity');
        });
        Schema::create('agt_submission_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agt_submission_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->uuid('operation_uuid');
            $table->unsignedBigInteger('operation_sequence');
            $table->string('kind', 24);
            $table->unsignedSmallInteger('reducer_version')->default(1);
            $table->json('outcome');
            $table->char('outcome_sha256', 64);
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('recorded_at');
            $table->uuid('correlation_id');
            $table->longText('conflict_response_body')->nullable();
            $table->char('conflict_response_sha256', 64)->nullable();
            $table->foreign(['agt_submission_id', 'workspace_id', 'legal_entity_id'], 'agt_observation_submission_fk')
                ->references(['id', 'workspace_id', 'legal_entity_id'])->on('agt_submissions')->restrictOnDelete();
            $table->foreign(['attempt_id', 'agt_submission_id', 'workspace_id', 'legal_entity_id'], 'agt_observation_attempt_fk')
                ->references(['id', 'agt_submission_id', 'workspace_id', 'legal_entity_id'])->on('agt_submission_attempts')->restrictOnDelete();
            $table->unique(['operation_uuid', 'kind', 'outcome_sha256'], 'agt_observation_replay_unique');
            $table->index(['agt_submission_id', 'operation_sequence', 'kind'], 'agt_observation_rebuild_index');
        });
        DB::statement("CREATE UNIQUE INDEX agt_observation_sequence_kind ON agt_submission_observations (agt_submission_id,operation_sequence,kind) WHERE kind <> 'conflict'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX agt_observation_operation_kind ON agt_submission_observations (operation_uuid,kind) WHERE kind <> 'conflict'");
            DB::statement("ALTER TABLE agt_submissions ADD CONSTRAINT agt_projection_control_check CHECK (operation_sequence >= 0 AND projection_revision >= 0 AND reducer_version = 1 AND ((active_operation_uuid IS NULL) = (operation_lease_expires_at IS NULL)) AND (qualified_projection IS NULL OR (json_typeof(qualified_projection) = 'object' AND json_typeof(qualified_projection->'version') = 'number' AND qualified_projection->>'version' = '1')))");
            DB::statement("ALTER TABLE agt_submission_observations ADD CONSTRAINT agt_observation_shape CHECK (operation_sequence >= 0 AND reducer_version = 1 AND kind IN ('claim','result','failure','legacy_import','conflict') AND json_typeof(outcome) = 'object' AND json_typeof(outcome->'version') = 'number' AND outcome->>'version' = '1' AND effective_at IS NULL)");
            DB::statement('CREATE OR REPLACE FUNCTION agt_observation_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'AGT observations are immutable\'; END; $$');
            DB::statement('CREATE TRIGGER agt_observation_append_only BEFORE UPDATE OR DELETE ON agt_submission_observations FOR EACH ROW EXECUTE FUNCTION agt_observation_append_only()');
        } else {
            DB::statement("CREATE UNIQUE INDEX agt_observation_operation_kind ON agt_submission_observations (operation_uuid,kind) WHERE kind <> 'conflict'");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::statement('CREATE TRIGGER agt_observation_'.strtolower($operation)." BEFORE $operation ON agt_submission_observations BEGIN SELECT RAISE(ABORT, 'AGT observations are immutable'); END");
            }
            DB::statement("CREATE TRIGGER agt_observation_shape BEFORE INSERT ON agt_submission_observations WHEN NEW.operation_sequence < 0 OR NEW.reducer_version <> 1 OR NEW.kind NOT IN ('claim','result','failure','legacy_import','conflict') OR json_type(NEW.outcome) <> 'object' OR json_extract(NEW.outcome,'$.version') IS NOT 1 OR NEW.effective_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Invalid AGT observation'); END");
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER agt_projection_'.strtolower($operation)." BEFORE $operation ON agt_submissions WHEN NEW.operation_sequence < 0 OR NEW.projection_revision < 0 OR NEW.reducer_version <> 1 OR ((NEW.active_operation_uuid IS NULL) <> (NEW.operation_lease_expires_at IS NULL)) OR (NEW.qualified_projection IS NOT NULL AND (json_type(NEW.qualified_projection) <> 'object' OR json_extract(NEW.qualified_projection,'$.version') IS NOT 1)) BEGIN SELECT RAISE(ABORT, 'Invalid AGT projection'); END");
            }
        }
        $this->shapeGuards();
        $this->outcomeGuards();
        $this->referenceGuards();
    }

    private function shapeGuards(): void
    {
        $allowed = ['classification' => ['unknown', 'partial', 'authoritative', 'conflicting'],
            'knowledge' => ['never_known', 'known', 'unknown_response', 'conflicting_evidence', 'legacy_unverified'],
            'sync' => ['idle', 'pending', 'failed'], 'delivery_state' => ['unknown', 'pending', 'sending', 'acknowledged', 'failed'],
            'reason' => ['not_submitted', 'delivery_pending', 'delivery_acknowledged', 'processing_reported', 'validation_reported', 'invalidity_reported', 'processing_cancelled', 'request_failed', 'refresh_pending', 'sync_failed', 'stale_observation', 'unknown_response', 'evidence_conflict', 'legacy_unverified'],
            'operational_status' => ['unknown', 'pending', 'sending', 'received', 'processing', 'valid', 'invalid', 'cancelled']];
        $checks = [];
        foreach ($allowed as $key => $values) {
            $field = DB::getDriverName() === 'pgsql' ? "qualified_projection->>'$key'" : "json_extract(NEW.qualified_projection,'$.$key')";
            $checks[] = "($field IS NOT NULL AND $field IN ('".implode("','", $values)."'))";
        }
        $keys = ['version', 'classification', 'knowledge', 'reported_state', 'sync', 'delivery_state', 'reason', 'observation_id', 'observed_at', 'last_known_state', 'last_known_observation_id', 'last_successful_sync_at', 'projected_at', 'applied_sequence', 'operational_status'];
        if (DB::getDriverName() === 'pgsql') {
            $checks[] = "jsonb_exists_all(qualified_projection::jsonb, ARRAY['".implode("','", $keys)."'])";
            // Equality with the allowlisted object prevents unexpected disclosure fields.
            $checks[] = 'qualified_projection::jsonb = jsonb_build_object('.implode(',', array_map(fn (string $key): string => "'$key',qualified_projection::jsonb->'$key'", $keys)).')';
            $checks[] = "json_typeof(qualified_projection->'applied_sequence') = 'number' AND (qualified_projection->>'applied_sequence')::bigint >= 0";
            foreach (['reported_state', 'last_known_state'] as $key) {
                $checks[] = "(json_typeof(qualified_projection->'$key') = 'null' OR qualified_projection->>'$key' IN ('valid','invalid','processing','processing_cancelled'))";
            }
            foreach (['observation_id', 'last_known_observation_id'] as $key) {
                $checks[] = "(json_typeof(qualified_projection->'$key') = 'null' OR (json_typeof(qualified_projection->'$key') = 'number' AND (qualified_projection->>'$key')::bigint > 0))";
            }
            foreach (['observed_at', 'last_successful_sync_at', 'projected_at'] as $key) {
                $checks[] = "json_typeof(qualified_projection->'$key') IN ('null','string')";
                $checks[] = "(json_typeof(qualified_projection->'$key') = 'null' OR to_char((qualified_projection->>'$key')::timestamptz AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') || '+00:00' = qualified_projection->>'$key')";
            }
            $checks[] = "(qualified_projection->>'knowledge' <> 'known' OR (qualified_projection->>'classification' = 'authoritative' AND qualified_projection->>'observation_id' IS NOT NULL AND qualified_projection->>'observed_at' IS NOT NULL))";
        } else {
            $checks[] = '(SELECT count(*) FROM json_each(NEW.qualified_projection)) = 15';
            foreach ($keys as $key) {
                $checks[] = "json_type(NEW.qualified_projection,'$.$key') IS NOT NULL";
            }
            $checks[] = "json_type(NEW.qualified_projection,'$.version') = 'integer'";
            $checks[] = "json_type(NEW.qualified_projection,'$.applied_sequence') = 'integer' AND json_extract(NEW.qualified_projection,'$.applied_sequence') >= 0";
            foreach (['reported_state', 'last_known_state'] as $key) {
                $checks[] = "(json_type(NEW.qualified_projection,'$.$key') = 'null' OR json_extract(NEW.qualified_projection,'$.$key') IN ('valid','invalid','processing','processing_cancelled'))";
            }
            foreach (['observation_id', 'last_known_observation_id'] as $key) {
                $checks[] = "(json_type(NEW.qualified_projection,'$.$key') = 'null' OR (json_type(NEW.qualified_projection,'$.$key') = 'integer' AND json_extract(NEW.qualified_projection,'$.$key') > 0))";
            }
            foreach (['observed_at', 'last_successful_sync_at', 'projected_at'] as $key) {
                $checks[] = "json_type(NEW.qualified_projection,'$.$key') IN ('null','text')";
                $checks[] = "(json_type(NEW.qualified_projection,'$.$key') = 'null' OR strftime('%Y-%m-%dT%H:%M:%S+00:00',json_extract(NEW.qualified_projection,'$.$key')) IS json_extract(NEW.qualified_projection,'$.$key'))";
            }
            $checks[] = "(json_extract(NEW.qualified_projection,'$.knowledge') <> 'known' OR (json_extract(NEW.qualified_projection,'$.classification') = 'authoritative' AND json_type(NEW.qualified_projection,'$.observation_id') = 'integer' AND json_type(NEW.qualified_projection,'$.observed_at') = 'text'))";
        }
        $field = fn (string $key): string => DB::getDriverName() === 'pgsql'
            ? "qualified_projection->>'$key'" : "json_extract(NEW.qualified_projection,'$.$key')";
        $knowledge = $field('knowledge');
        $sync = $field('sync');
        $reported = $field('reported_state');
        $delivery = $field('delivery_state');
        $checks[] = $field('operational_status')." = CASE
            WHEN $knowledge = 'known' AND $sync <> 'failed' AND $reported = 'processing' THEN 'processing'
            WHEN $knowledge = 'known' AND $sync = 'idle' THEN CASE WHEN $reported = 'processing_cancelled' THEN 'cancelled' ELSE COALESCE($reported,'unknown') END
            WHEN $knowledge = 'never_known' AND $sync <> 'failed' THEN CASE $delivery WHEN 'sending' THEN 'sending' WHEN 'acknowledged' THEN 'received' WHEN 'pending' THEN 'pending' ELSE 'unknown' END
            ELSE 'unknown' END";
        $predicate = implode(' AND ', $checks);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agt_submissions ADD CONSTRAINT agt_projection_enums CHECK (qualified_projection IS NULL OR ($predicate) IS TRUE)");
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER agt_projection_enums_'.strtolower($operation)." BEFORE $operation ON agt_submissions WHEN NEW.qualified_projection IS NOT NULL AND ($predicate) IS NOT TRUE BEGIN SELECT RAISE(ABORT, 'Invalid AGT projection enums'); END");
            }
        }
    }

    private function outcomeGuards(): void
    {
        $base = ['version', 'classification', 'knowledge', 'reported_state', 'sync', 'delivery_state', 'reason', 'successful_sync', 'eligible_generation'];
        $shapes = ['claim' => ['version', 'operation', 'execution_uuid', 'queue_attempt', 'request_identity_sha256', 'frozen_request_sha256'],
            'result' => [...$base, 'response_sha256', 'request_sha256', 'evidence_digest'],
            'failure' => $base, 'legacy_import' => $base, 'conflict' => ['version', 'classification', 'evidence_digest']];
        $cases = [];
        foreach ($shapes as $kind => $keys) {
            $quoted = "'".implode("','", $keys)."'";
            if (DB::getDriverName() === 'pgsql') {
                $cases[] = "WHEN kind = '$kind' THEN jsonb_exists_all(outcome::jsonb, ARRAY[$quoted]) AND (outcome::jsonb - ARRAY[$quoted]) = '{}'::jsonb";
            } else {
                $conditions = ['(SELECT count(*) FROM json_each(NEW.outcome)) = '.count($keys)];
                foreach ($keys as $key) {
                    $conditions[] = "json_type(NEW.outcome,'$.$key') IS NOT NULL";
                }
                $cases[] = "WHEN NEW.kind = '$kind' THEN ".implode(' AND ', $conditions);
            }
        }
        $predicate = 'CASE '.implode(' ', $cases).' ELSE FALSE END';
        $reasons = ['not_submitted', 'delivery_pending', 'delivery_acknowledged', 'processing_reported', 'validation_reported', 'invalidity_reported', 'processing_cancelled', 'request_failed', 'refresh_pending', 'sync_failed', 'stale_observation', 'unknown_response', 'evidence_conflict', 'legacy_unverified'];
        $reasonList = "'".implode("','", $reasons)."'";
        if (DB::getDriverName() === 'pgsql') {
            $predicate .= " AND (kind IN ('claim','conflict') OR (json_typeof(outcome->'successful_sync') = 'boolean' AND json_typeof(outcome->'eligible_generation') = 'boolean' AND outcome->>'classification' IN ('unknown','partial','authoritative','conflicting') AND outcome->>'knowledge' IN ('never_known','known','unknown_response','conflicting_evidence','legacy_unverified') AND outcome->>'sync' IN ('idle','pending','failed') AND outcome->>'delivery_state' IN ('unknown','pending','sending','acknowledged','failed')))";
            $predicate .= " AND (kind IN ('claim','conflict') OR (outcome->>'reason' IN ($reasonList) AND (json_typeof(outcome->'reported_state') = 'null' OR outcome->>'reported_state' IN ('valid','invalid','processing','processing_cancelled'))))";
            $predicate .= " AND (kind <> 'claim' OR (outcome->>'operation' IN ('register_invoice','query_status') AND json_typeof(outcome->'queue_attempt') = 'number' AND outcome->>'queue_attempt' ~ '^[1-9][0-9]*$'))";
            $predicate .= " AND (kind <> 'conflict' OR outcome->>'classification' = 'conflicting')";
            DB::statement("ALTER TABLE agt_submission_observations ADD CONSTRAINT agt_outcome_closed CHECK (($predicate) IS TRUE)");
        } else {
            $predicate .= " AND (NEW.kind IN ('claim','conflict') OR (json_type(NEW.outcome,'$.successful_sync') IN ('true','false') AND json_type(NEW.outcome,'$.eligible_generation') IN ('true','false') AND json_extract(NEW.outcome,'$.classification') IN ('unknown','partial','authoritative','conflicting') AND json_extract(NEW.outcome,'$.knowledge') IN ('never_known','known','unknown_response','conflicting_evidence','legacy_unverified') AND json_extract(NEW.outcome,'$.sync') IN ('idle','pending','failed') AND json_extract(NEW.outcome,'$.delivery_state') IN ('unknown','pending','sending','acknowledged','failed')))";
            $predicate .= " AND (NEW.kind IN ('claim','conflict') OR (json_extract(NEW.outcome,'$.reason') IN ($reasonList) AND (json_type(NEW.outcome,'$.reported_state') = 'null' OR json_extract(NEW.outcome,'$.reported_state') IN ('valid','invalid','processing','processing_cancelled'))))";
            $predicate .= " AND (NEW.kind <> 'claim' OR (json_extract(NEW.outcome,'$.operation') IN ('register_invoice','query_status') AND json_type(NEW.outcome,'$.queue_attempt') = 'integer' AND json_extract(NEW.outcome,'$.queue_attempt') > 0))";
            $predicate .= " AND (NEW.kind <> 'conflict' OR json_extract(NEW.outcome,'$.classification') = 'conflicting')";
            DB::statement("CREATE TRIGGER agt_outcome_closed BEFORE INSERT ON agt_submission_observations WHEN ($predicate) IS NOT TRUE BEGIN SELECT RAISE(ABORT, 'Invalid AGT outcome shape'); END");
        }
    }

    private function referenceGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION agt_projection_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE reference_id bigint;
BEGIN
    IF NEW.qualified_projection IS NOT NULL THEN
        FOR reference_id IN SELECT value::bigint FROM json_each_text(NEW.qualified_projection)
            WHERE key IN ('observation_id','last_known_observation_id') AND value IS NOT NULL LOOP
            IF NOT EXISTS (SELECT 1 FROM agt_submission_observations o WHERE o.id = reference_id
                AND o.agt_submission_id = NEW.id AND o.workspace_id = NEW.workspace_id AND o.legal_entity_id = NEW.legal_entity_id) THEN
                RAISE EXCEPTION 'Invalid AGT projection evidence binding';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END; $$
SQL);
            DB::statement('CREATE TRIGGER agt_projection_reference_guard BEFORE INSERT OR UPDATE ON agt_submissions FOR EACH ROW EXECUTE FUNCTION agt_projection_reference_guard()');
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION agt_operation_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
    IF NEW.kind IN ('result','failure','conflict') AND NOT EXISTS (SELECT 1 FROM agt_submission_observations o
        WHERE o.operation_uuid = NEW.operation_uuid AND o.kind = 'claim' AND o.agt_submission_id = NEW.agt_submission_id
        AND o.workspace_id = NEW.workspace_id AND o.legal_entity_id = NEW.legal_entity_id AND o.operation_sequence = NEW.operation_sequence) THEN
        RAISE EXCEPTION 'Invalid AGT operation binding';
    END IF;
    IF NEW.kind = 'result' AND NOT EXISTS (SELECT 1 FROM agt_submission_attempts a
        JOIN agt_submission_observations c ON c.operation_uuid = NEW.operation_uuid AND c.kind = 'claim'
        WHERE a.id = NEW.attempt_id AND a.agt_submission_id = NEW.agt_submission_id
        AND a.attempt_number = NEW.operation_sequence AND a.operation = c.outcome->>'operation') THEN
        RAISE EXCEPTION 'Invalid AGT result attempt binding';
    END IF;
    RETURN NEW;
END; $$
SQL);
            DB::statement('CREATE TRIGGER agt_operation_reference_guard BEFORE INSERT ON agt_submission_observations FOR EACH ROW EXECUTE FUNCTION agt_operation_reference_guard()');
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER agt_projection_reference_'.strtolower($operation)." BEFORE $operation ON agt_submissions WHEN NEW.qualified_projection IS NOT NULL AND EXISTS (SELECT 1 FROM json_each(NEW.qualified_projection) r WHERE r.key IN ('observation_id','last_known_observation_id') AND r.value IS NOT NULL AND NOT EXISTS (SELECT 1 FROM agt_submission_observations o WHERE o.id = r.value AND o.agt_submission_id = NEW.id AND o.workspace_id = NEW.workspace_id AND o.legal_entity_id = NEW.legal_entity_id)) BEGIN SELECT RAISE(ABORT, 'Invalid AGT projection evidence binding'); END");
            }
            DB::statement("CREATE TRIGGER agt_operation_reference_guard BEFORE INSERT ON agt_submission_observations WHEN NEW.kind IN ('result','failure','conflict') AND NOT EXISTS (SELECT 1 FROM agt_submission_observations o WHERE o.operation_uuid = NEW.operation_uuid AND o.kind = 'claim' AND o.agt_submission_id = NEW.agt_submission_id AND o.workspace_id = NEW.workspace_id AND o.legal_entity_id = NEW.legal_entity_id AND o.operation_sequence = NEW.operation_sequence) BEGIN SELECT RAISE(ABORT, 'Invalid AGT operation binding'); END");
            DB::statement("CREATE TRIGGER agt_result_attempt_guard BEFORE INSERT ON agt_submission_observations WHEN NEW.kind = 'result' AND NOT EXISTS (SELECT 1 FROM agt_submission_attempts a JOIN agt_submission_observations c ON c.operation_uuid = NEW.operation_uuid AND c.kind = 'claim' WHERE a.id = NEW.attempt_id AND a.agt_submission_id = NEW.agt_submission_id AND a.attempt_number = NEW.operation_sequence AND a.operation = json_extract(c.outcome,'$.operation')) BEGIN SELECT RAISE(ABORT, 'Invalid AGT result attempt binding'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('agt_submission_observations')->exists() || DB::table('agt_submissions')->whereNotNull('qualified_projection')->exists()) {
            throw new RuntimeException('AGT projection evidence exists; use a reviewed forward repair.');
        }
        Schema::dropIfExists('agt_submission_observations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS agt_observation_append_only()');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_reference_guard ON agt_submissions');
            DB::statement('DROP FUNCTION IF EXISTS agt_projection_reference_guard()');
            DB::statement('DROP FUNCTION IF EXISTS agt_operation_reference_guard()');
            DB::statement('ALTER TABLE agt_submissions DROP CONSTRAINT IF EXISTS agt_projection_control_check');
            DB::statement('ALTER TABLE agt_submissions DROP CONSTRAINT IF EXISTS agt_projection_enums');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_reference_insert');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_reference_update');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_enums_insert');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_enums_update');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_insert');
            DB::statement('DROP TRIGGER IF EXISTS agt_projection_update');
        }
        Schema::table('agt_submission_attempts', fn (Blueprint $table) => $table->dropUnique('agt_attempt_observation_identity'));
        Schema::table('agt_submissions', fn (Blueprint $table) => $table->dropColumn(['operation_sequence', 'active_operation_uuid', 'operation_lease_expires_at', 'projection_revision', 'reducer_version', 'qualified_projection']));
    }
};
