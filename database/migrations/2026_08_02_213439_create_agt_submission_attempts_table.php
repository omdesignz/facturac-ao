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
        Schema::create('agt_submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('agt_submission_id');
            $table->string('operation', 32);
            $table->unsignedInteger('attempt_number');
            $table->string('endpoint_path', 255);
            $table->longText('request_body');
            $table->char('request_body_sha256', 64);
            $table->longText('response_body')->nullable();
            $table->char('response_body_sha256', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->json('error_codes')->nullable();
            $table->string('safe_message', 512);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['agt_submission_id', 'workspace_id', 'legal_entity_id'],
                'agt_submission_attempts_submission_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_submissions')
                ->cascadeOnDelete();
            $table->unique(
                ['agt_submission_id', 'operation', 'attempt_number'],
                'agt_submission_attempts_number_unique',
            );
            $table->index(
                ['workspace_id', 'legal_entity_id', 'started_at'],
                'agt_submission_attempts_tenant_started_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agt_submission_attempts');
    }
};
