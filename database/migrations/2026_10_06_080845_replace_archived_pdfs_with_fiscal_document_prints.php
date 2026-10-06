<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents are no longer kept as PDF files. What is kept instead is the
 * little a re-render needs to reproduce the document exactly as issued:
 * which frozen layout it uses, the issuer details as they stood at issue,
 * the logo it carried, and a fingerprint of everything it prints.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('archived_pdfs');

        Schema::create('fiscal_document_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->string('layout_version', 20);
            $table->json('issuer');
            $table->string('logo_path')->nullable();
            $table->char('logo_sha256', 64)->nullable();
            $table->char('source_sha256', 64);
            $table->timestamps();

            $table->unique('fiscal_document_id');
            // Logo deletion asks whether any document still prints a file.
            $table->index(['legal_entity_id', 'logo_path']);
            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_document_prints_document_tenant_fk',
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
        Schema::dropIfExists('fiscal_document_prints');

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

            $table->unique('fiscal_document_id');
            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'archived_pdfs_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('fiscal_documents')
                ->restrictOnDelete();
        });
    }
};
