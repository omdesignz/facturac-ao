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
        Schema::create('agt_connection_checks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('agt_connection_id');
            $table->string('operation', 64);
            $table->string('status', 32)->default('running');
            $table->uuid('probe_uuid')->unique();
            $table->string('endpoint_path', 255);
            $table->char('request_body_sha256', 64)->nullable();
            $table->char('response_body_sha256', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result_code', 128)->nullable();
            $table->json('error_codes')->nullable();
            $table->string('safe_message', 500);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['agt_connection_id', 'workspace_id', 'legal_entity_id'])
                ->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_connections')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['legal_entity_id', 'operation', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agt_connection_checks');
    }
};
