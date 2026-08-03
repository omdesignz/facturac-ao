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
        Schema::create('fiscal_series', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('establishment_id');
            $table->unsignedBigInteger('agt_connection_id');
            $table->string('series_code', 60);
            $table->unsignedSmallInteger('series_year');
            $table->char('document_type', 2);
            $table->char('status', 1);
            $table->char('contingency_indicator', 1)->default('N');
            $table->string('invoicing_method', 4)->default('FESF');
            $table->date('agt_creation_date')->nullable();
            $table->string('agt_first_document_no', 60);
            $table->string('agt_last_document_no', 60);
            $table->string('agt_first_document_created', 60)->nullable();
            $table->string('agt_last_document_created', 60)->nullable();
            $table->unsignedBigInteger('first_authorized_number');
            $table->unsignedBigInteger('last_authorized_number');
            $table->unsignedBigInteger('next_number');
            $table->unsignedBigInteger('last_issued_number')->nullable();
            $table->date('last_document_date')->nullable();
            $table->timestamp('synchronized_at');
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'fiscal_series_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->restrictOnDelete();
            $table->foreign(
                ['establishment_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_series_establishment_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('establishments')
                ->restrictOnDelete();
            $table->foreign(
                ['agt_connection_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_series_connection_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_connections')
                ->restrictOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id', 'establishment_id'],
                'fiscal_series_tenant_establishment_id_unique',
            );
            $table->unique(
                ['legal_entity_id', 'series_code'],
                'fiscal_series_entity_code_unique',
            );
            $table->index([
                'workspace_id',
                'legal_entity_id',
                'establishment_id',
                'document_type',
                'series_year',
                'status',
            ], 'fiscal_series_allocation_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_series');
    }
};
