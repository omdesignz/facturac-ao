<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit and debit notes correct an earlier document, and the AGT requires the
 * corrected document and the reason to travel with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->foreignId('references_document_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('fiscal_documents')
                ->nullOnDelete();
            $table->string('references_document_no')->nullable()->after('references_document_id');
            $table->string('adjustment_reason', 200)->nullable()->after('references_document_no');

            $table->index('references_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('references_document_id');
            $table->dropColumn(['references_document_no', 'adjustment_reason']);
        });
    }
};
