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
        Schema::create('fiscal_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedBigInteger('agt_submission_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 40);
            $table->char('agt_document_status', 1)->nullable();
            $table->json('safe_context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_document_events_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('fiscal_documents')
                ->restrictOnDelete();
            $table->foreign(
                ['agt_submission_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_document_events_submission_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_submissions')
                ->restrictOnDelete();
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->index(
                ['workspace_id', 'legal_entity_id', 'fiscal_document_id', 'occurred_at'],
                'fiscal_document_events_tenant_document_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_events');
    }
};
