<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->unique(['legal_entity_id', 'document_no'], 'fiscal_documents_entity_number_unique');
            $table->dropUnique(['workspace_id', 'document_no']);
        });
    }

    public function down(): void
    {
        if (DB::table('fiscal_documents')->selectRaw('1')->whereNotNull('document_no')->groupBy('workspace_id', 'document_no')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cross-entity fiscal numbers exist; rollback would lose valid identities. Use a forward migration.');
        }
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'document_no']);
            $table->dropUnique('fiscal_documents_entity_number_unique');
        });
    }
};
