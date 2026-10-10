<?php

use App\Fiscal\IntegrationSchemaGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replaceChecks("'documents:read','customers:read','catalogue:read','documents:agt-status:read'");
        });
    }

    public function down(): void
    {
        foreach (['integration_scopes', 'integration_credential_scopes'] as $table) {
            if (DB::table($table)->where('scope', 'documents:agt-status:read')->exists()) {
                throw new RuntimeException('Qualified-status grant evidence exists; forward repair required.');
            }
        }
        if (Schema::hasTable('activity_log') && DB::table('activity_log')->whereIn('event', [
            'documents.agt-status.read', 'documents.agt-status.read.denied',
        ])->orWhere('properties->capability_version', 2)->exists()) {
            throw new RuntimeException('Qualified-status audit evidence exists; forward repair required.');
        }
        DB::transaction(function (): void {
            $this->replaceChecks("'documents:read','customers:read','catalogue:read'");
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
