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
        Schema::table('establishments', function (Blueprint $table) {
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'establishments_tenant_entity_id_unique',
            );
        });

        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('establishment_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('document_type', 2)->default('FT');
            $table->string('status', 24)->default('draft');
            $table->char('agt_document_status', 1)->default('N');
            $table->string('document_no', 60)->nullable();
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3)->default('AOA');
            $table->string('customer_name');
            $table->string('customer_tax_identification_number', 32);
            $table->char('customer_country_code', 2)->default('AO');
            $table->string('customer_address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('settlement_total_minor')->default(0);
            $table->unsignedBigInteger('net_total_minor')->default(0);
            $table->unsignedBigInteger('tax_payable_minor')->default(0);
            $table->unsignedBigInteger('gross_total_minor')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('payload_schema_version', 16)->default('1.2');
            $table->char('calculation_sha256', 64);
            $table->timestamp('system_entry_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'fiscal_documents_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->restrictOnDelete();
            $table->foreign(
                ['establishment_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_documents_establishment_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('establishments')
                ->restrictOnDelete();
            $table->foreign(
                ['customer_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_documents_customer_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'fiscal_documents_tenant_entity_id_unique',
            );
            $table->unique(['workspace_id', 'document_no']);
            $table->index(
                ['workspace_id', 'legal_entity_id', 'status', 'document_date'],
                'fiscal_documents_tenant_status_date_index',
            );
            $table->index(['workspace_id', 'customer_id', 'document_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropUnique('establishments_tenant_entity_id_unique');
        });
    }
};
