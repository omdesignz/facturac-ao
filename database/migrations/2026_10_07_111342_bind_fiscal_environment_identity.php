<?php

use App\Fiscal\FiscalEnvironmentBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        $backfill = new FiscalEnvironmentBackfill;
        $backfill->scan();
        foreach (['fiscal_series', 'fiscal_documents', 'agt_submissions', 'agt_connection_checks'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('environment', 24)->default('unresolved'));
        }
        $backfill->scan(apply: true);
        Schema::table('agt_connections', fn (Blueprint $table) => $table->unique(['id', 'workspace_id', 'legal_entity_id', 'environment'], 'agt_connection_environment_identity'));
        Schema::table('fiscal_series', function (Blueprint $table): void {
            $table->unique(['legal_entity_id', 'environment', 'series_code'], 'fiscal_series_environment_code_unique');
            $table->dropUnique('fiscal_series_entity_code_unique');
            $table->unique(['id', 'workspace_id', 'legal_entity_id', 'environment'], 'fiscal_series_environment_identity');
        });
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->unique(['legal_entity_id', 'environment', 'document_no'], 'fiscal_documents_environment_number_unique');
            $table->dropUnique('fiscal_documents_entity_number_unique');
            $table->unique(['id', 'workspace_id', 'legal_entity_id', 'environment'], 'fiscal_documents_environment_identity');
            $table->foreign(['fiscal_series_id', 'workspace_id', 'legal_entity_id', 'environment'], 'document_series_environment_fk')
                ->references(['id', 'workspace_id', 'legal_entity_id', 'environment'])->on('fiscal_series')->restrictOnDelete();
        });
        Schema::table('agt_submissions', function (Blueprint $table): void {
            $table->unique(['legal_entity_id', 'environment', 'request_id'], 'agt_submissions_environment_request_unique');
            $table->dropUnique('agt_submissions_entity_request_unique');
            $table->foreign(['fiscal_document_id', 'workspace_id', 'legal_entity_id', 'environment'], 'submission_document_environment_fk')
                ->references(['id', 'workspace_id', 'legal_entity_id', 'environment'])->on('fiscal_documents')->restrictOnDelete();
        });
        foreach (['fiscal_series', 'fiscal_documents', 'agt_submissions', 'agt_connection_checks'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->foreign(['agt_connection_id', 'workspace_id', 'legal_entity_id', 'environment'], $name.'_environment_fk')
                    ->references(['id', 'workspace_id', 'legal_entity_id', 'environment'])->on('agt_connections')->restrictOnDelete();
            });
        }
        if (DB::getDriverName() === 'sqlite') {
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Environment migration failed foreign-key integrity verification.');
            }
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }
    }

    public function down(): void
    {
        foreach (['fiscal_series', 'fiscal_documents', 'agt_submissions', 'agt_connection_checks'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Environment evidence exists; preserve it and use a forward migration or restore the pre-migration backup.');
            }
        }
        foreach (['agt_connection_checks', 'agt_submissions', 'fiscal_documents', 'fiscal_series'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['agt_connection_id', 'workspace_id', 'legal_entity_id', 'environment'] : $name.'_environment_fk'));
        }
        Schema::table('agt_submissions', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['fiscal_document_id', 'workspace_id', 'legal_entity_id', 'environment'] : 'submission_document_environment_fk');
            $table->dropUnique('agt_submissions_environment_request_unique');
            $table->unique(['legal_entity_id', 'request_id'], 'agt_submissions_entity_request_unique');
        });
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['fiscal_series_id', 'workspace_id', 'legal_entity_id', 'environment'] : 'document_series_environment_fk');
            $table->dropUnique('fiscal_documents_environment_number_unique');
            $table->dropUnique('fiscal_documents_environment_identity');
            $table->unique(['legal_entity_id', 'document_no'], 'fiscal_documents_entity_number_unique');
        });
        Schema::table('fiscal_series', function (Blueprint $table): void {
            $table->dropUnique('fiscal_series_environment_code_unique');
            $table->dropUnique('fiscal_series_environment_identity');
            $table->unique(['legal_entity_id', 'series_code'], 'fiscal_series_entity_code_unique');
        });
        Schema::table('agt_connections', fn (Blueprint $table) => $table->dropUnique('agt_connection_environment_identity'));
        foreach (['fiscal_series', 'fiscal_documents', 'agt_submissions', 'agt_connection_checks'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('environment'));
        }
    }
};
