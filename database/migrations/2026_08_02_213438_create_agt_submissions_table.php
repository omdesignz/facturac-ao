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
        Schema::create('agt_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->uuid('submission_uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedBigInteger('agt_connection_id');
            $table->string('operation', 32)->default('register_invoice');
            $table->string('schema_version', 16);
            $table->string('status', 24)->default('pending');
            $table->string('request_id', 15)->nullable();
            $table->longText('request_body');
            $table->char('request_body_sha256', 64);
            $table->char('last_response_body_sha256', 64)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_result_code', 64)->nullable();
            $table->json('last_error_codes')->nullable();
            $table->string('safe_message', 512);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'agt_submissions_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('fiscal_documents')
                ->restrictOnDelete();
            $table->foreign(
                ['agt_connection_id', 'workspace_id', 'legal_entity_id'],
                'agt_submissions_connection_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_connections')
                ->restrictOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'agt_submissions_tenant_id_unique',
            );
            $table->unique('fiscal_document_id');
            $table->unique(
                ['legal_entity_id', 'request_id'],
                'agt_submissions_entity_request_unique',
            );
            $table->index(
                ['workspace_id', 'legal_entity_id', 'status', 'next_attempt_at'],
                'agt_submissions_due_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agt_submissions');
    }
};
