<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Billing integrity requires PostgreSQL or SQLite.');
        }
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->index(['workspace_id', 'legal_entity_id', 'environment', 'document_date', 'id'], 'fiscal_documents_analytics_month_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropIndex('fiscal_documents_analytics_month_idx');
        });
    }
};
