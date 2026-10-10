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
        DB::transaction(function (): void {
            $this->replaceChecks("'documents:read','customers:read','catalogue:read'");
            foreach (['customers', 'catalogue_items'] as $table) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index(['workspace_id', 'legal_entity_id', 'id'], $table.'_scoped_read_order'));
            }
        });
    }

    public function down(): void
    {
        foreach (['integration_scopes', 'integration_credential_scopes'] as $table) {
            if (DB::table($table)->whereIn('scope', ['customers:read', 'catalogue:read'])->exists()) {
                throw new RuntimeException('Master-data grant evidence exists; forward repair required.');
            }
        }
        if (Schema::hasTable('activity_log') && DB::table('activity_log')->whereIn('event', [
            'customers.list', 'customers.read', 'customers.read.denied', 'catalogue.list', 'catalogue.read', 'catalogue.read.denied',
        ])->exists()) {
            throw new RuntimeException('Master-data audit evidence exists; forward repair required.');
        }
        DB::transaction(function (): void {
            $this->replaceChecks("'documents:read'");
            foreach (['customers', 'catalogue_items'] as $table) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($table.'_scoped_read_order'));
            }
        });
    }

    private function replaceChecks(string $scopes): void
    {
        DB::transaction(function () use ($scopes): void {
            foreach (['integration_scopes' => ['integration_id', 'integrations'], 'integration_credential_scopes' => ['credential_id', 'integration_credentials']] as $table => [$key, $parent]) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_scope_replacement CHECK (scope IN ($scopes))");
                    DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_scope_check");
                    DB::statement("ALTER TABLE $table RENAME CONSTRAINT {$table}_scope_replacement TO {$table}_scope_check");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::statement("CREATE TABLE {$table}_replacement ($key BIGINT NOT NULL REFERENCES $parent(id) ON DELETE RESTRICT,
                        scope VARCHAR(32) NOT NULL CHECK (scope IN ($scopes)), PRIMARY KEY($key,scope))");
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
                throw new RuntimeException('Integration scope foreign-key integrity failed.');
            }
        });
    }
};
