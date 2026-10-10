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
        Schema::create('assistant_provider_controls', function (Blueprint $table): void {
            $table->uuid('budget_id')->primary();
            $table->boolean('enabled')->default(false);
            $table->boolean('circuit_blocked')->default(true);
            $table->string('profile', 80);
            $table->string('policy', 40);
            $table->string('approval_reference', 80)->nullable();
            $table->timestampTz('approval_expires_at')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->timestampsTz();
        });
        Schema::create('assistant_provider_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('budget_id');
            $table->string('scope', 24);
            $table->string('scope_key', 40);
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            foreach (['reserved_micro_usd', 'actual_input_tokens', 'actual_output_tokens', 'actual_micro_usd', 'unknown_usage_count', 'attempt_count'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->unique(['budget_id', 'scope', 'scope_key', 'window_start'], 'assistant_provider_window_identity');
            $table->foreign('budget_id')->references('budget_id')->on('assistant_provider_controls')->restrictOnDelete();
        });
        Schema::create('assistant_provider_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('interaction_id')->unique();
            $table->uuid('budget_id');
            $table->uuid('actor_attribution_id');
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreign(['legal_entity_id', 'workspace_id'], 'assistant_provider_context_fk')->references(['id', 'workspace_id'])->on('legal_entities')->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('profile', 80);
            $table->string('price_profile', 80);
            $table->string('policy', 40);
            $table->timestampTz('day_start');
            $table->timestampTz('month_start');
            $table->bigInteger('reserved_micro_usd');
            $table->unsignedInteger('estimated_tokens');
            $table->unsignedInteger('request_bytes');
            $table->string('state', 16)->default('admitted');
            foreach (['input_tokens', 'output_tokens', 'actual_micro_usd'] as $column) {
                $table->bigInteger($column)->nullable();
            }
            $table->string('outcome', 40)->nullable();
            $table->timestampTz('admitted_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->index(['state', 'admitted_at', 'id'], 'assistant_provider_recovery_index');
            $table->foreign('budget_id')->references('budget_id')->on('assistant_provider_controls')->restrictOnDelete();
        });
        Schema::create('assistant_provider_tenants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('policy', 40);
            $table->string('profile', 80);
            $table->uuid('owner_attribution_id');
            $table->string('approval_reference', 80);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();

        });
        Schema::create('assistant_provider_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('actor_attribution_id');
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('policy', 40);
            $table->timestampTz('acknowledged_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->unique(['actor_attribution_id', 'workspace_id', 'policy'], 'assistant_provider_ack_identity');
        });
        DB::statement('CREATE UNIQUE INDEX assistant_provider_tenant_active ON assistant_provider_tenants (workspace_id, policy, profile) WHERE revoked_at IS NULL');
        IntegrationSchemaGuards::immutable('assistant_provider_controls', ['budget_id']);
        IntegrationSchemaGuards::immutable('assistant_provider_windows', ['budget_id', 'scope', 'scope_key', 'window_start', 'window_end']);
        IntegrationSchemaGuards::immutable('assistant_provider_attempts', ['id', 'interaction_id', 'budget_id', 'actor_attribution_id', 'workspace_id', 'legal_entity_id', 'environment', 'profile', 'price_profile', 'policy', 'day_start', 'month_start', 'reserved_micro_usd', 'estimated_tokens', 'request_bytes', 'admitted_at']);
        IntegrationSchemaGuards::immutable('assistant_provider_tenants', ['workspace_id', 'policy', 'profile', 'owner_attribution_id', 'approval_reference', 'expires_at'], terminal: true);
        IntegrationSchemaGuards::immutable('assistant_provider_acknowledgements', ['actor_attribution_id', 'workspace_id', 'policy']);
        foreach (['assistant_provider_attempts' => 'actor_attribution_id', 'assistant_provider_tenants' => 'owner_attribution_id', 'assistant_provider_acknowledgements' => 'actor_attribution_id'] as $table => $column) {
            IntegrationSchemaGuards::uuid($table, $column);
        }
        foreach (['id', 'interaction_id'] as $column) {
            IntegrationSchemaGuards::uuid('assistant_provider_attempts', $column);
        }
        $counterCondition = implode(' OR ', array_map(fn (string $column): string => "NEW.$column < OLD.$column", ['reserved_micro_usd', 'actual_input_tokens', 'actual_output_tokens', 'actual_micro_usd', 'unknown_usage_count', 'attempt_count']));
        $this->transitionGuard('assistant_provider_windows', $counterCondition);
        $comparison = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $terminalCondition = implode(' OR ', array_map(fn (string $column): string => "NEW.$column $comparison OLD.$column", ['state', 'input_tokens', 'output_tokens', 'actual_micro_usd', 'outcome', 'finalized_at']));
        $this->transitionGuard('assistant_provider_attempts', "OLD.state <> 'admitted' AND ($terminalCondition)");
        $this->check('assistant_provider_windows', "scope IN ('deployment_month','deployment_day','workspace_month','workspace_day','user_day') AND length(scope_key) > 0 AND window_end > window_start AND reserved_micro_usd >= 0 AND actual_input_tokens >= 0 AND actual_output_tokens >= 0 AND actual_micro_usd >= 0 AND unknown_usage_count >= 0 AND attempt_count >= 0");
        $this->check('assistant_provider_attempts', "environment = 'production' AND state IN ('admitted','received','failed','usage_unknown') AND reserved_micro_usd = 552816 AND estimated_tokens BETWEEN 1024 AND 8192 AND request_bytes BETWEEN 1 AND 7168 AND (input_tokens IS NULL OR input_tokens BETWEEN 0 AND 1000000) AND (output_tokens IS NULL OR output_tokens BETWEEN 0 AND 1024) AND (actual_micro_usd IS NULL OR actual_micro_usd >= 0)");
    }

    private function transitionGuard(string $table, string $condition): void
    {
        $name = $table.'_transition';
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Invalid provider transition'; END IF; RETURN NEW; END; \$\$");
            DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
        } else {
            DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Invalid provider transition'); END");
        }
    }

    private function check(string $table, string $expression): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values CHECK ($expression)");
        } else {
            $columns = Schema::getColumnListing($table);
            foreach (['INSERT', 'UPDATE'] as $event) {
                $qualified = preg_replace_callback('/\b[a-z_]+\b/', fn (array $match): string => in_array($match[0], $columns, true) ? 'NEW.'.$match[0] : $match[0], $expression);
                DB::statement("CREATE TRIGGER {$table}_values_$event BEFORE $event ON $table WHEN NOT ($qualified) BEGIN SELECT RAISE(ABORT, 'Invalid provider metadata'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['assistant_provider_acknowledgements', 'assistant_provider_tenants', 'assistant_provider_attempts', 'assistant_provider_windows', 'assistant_provider_controls'] as $table) {
            IntegrationSchemaGuards::drop($table);
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP FUNCTION IF EXISTS {$table}_transition()");
            }
        }
    }
};
