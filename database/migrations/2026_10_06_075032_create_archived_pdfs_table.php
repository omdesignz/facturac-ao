<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('archived_pdfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->string('disk', 40);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('byte_size');
            $table->string('renderer', 60);
            $table->timestamp('rendered_at');
            $table->timestamps();

            // One as-issued copy per document: the constraint, not a lock, is
            // what guarantees two racing renders cannot both become the record.
            $table->unique('fiscal_document_id');
            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'archived_pdfs_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('fiscal_documents')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archived_pdfs');
    }
};
