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
        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'catalogue_items_tenant_entity_id_unique',
            );
        });

        Schema::create('transport_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('establishment_id');
            $table->char('document_type', 2);
            $table->string('series_code', 32);
            $table->unsignedSmallInteger('series_year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedBigInteger('last_issued_number')->nullable();
            $table->date('last_movement_date')->nullable();
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'transport_sequences_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->restrictOnDelete();
            $table->foreign(
                ['establishment_id', 'workspace_id', 'legal_entity_id'],
                'transport_sequences_establishment_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('establishments')
                ->restrictOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id', 'establishment_id'],
                'transport_sequences_tenant_id_unique',
            );
            $table->unique(
                ['legal_entity_id', 'establishment_id', 'document_type', 'series_code', 'series_year'],
                'transport_sequences_scope_unique',
            );
        });

        Schema::create('transport_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('establishment_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('transport_document_sequence_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('document_type', 2)->default('GT');
            $table->string('status', 24)->default('draft');
            $table->string('document_no', 60)->nullable();
            $table->unsignedBigInteger('issue_sequence')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->date('movement_date');
            $table->dateTime('movement_start_at');
            $table->dateTime('movement_end_at')->nullable();
            $table->string('recipient_name');
            $table->string('recipient_tax_identification_number', 32);
            $table->char('recipient_country_code', 2)->default('AO');
            $table->string('recipient_address');
            $table->string('recipient_city');
            $table->string('recipient_province', 80)->nullable();
            $table->string('origin_address');
            $table->string('origin_city');
            $table->string('origin_province', 80)->nullable();
            $table->char('origin_country_code', 2)->default('AO');
            $table->string('destination_address');
            $table->string('destination_city');
            $table->string('destination_province', 80)->nullable();
            $table->char('destination_country_code', 2)->default('AO');
            $table->string('transporter_name')->nullable();
            $table->string('transporter_tax_identification_number', 32)->nullable();
            $table->string('vehicle_registration', 32)->nullable();
            $table->unsignedBigInteger('gross_weight_grams')->nullable();
            $table->unsignedInteger('package_count')->nullable();
            $table->char('currency_code', 3)->default('AOA');
            $table->unsignedBigInteger('net_total_minor')->default(0);
            $table->unsignedBigInteger('tax_payable_minor')->default(0);
            $table->unsignedBigInteger('gross_total_minor')->default(0);
            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->string('hash_control', 70)->nullable();
            $table->string('software_product_id')->nullable();
            $table->string('software_product_version', 64)->nullable();
            $table->string('software_validation_number')->nullable();
            $table->timestamp('system_entry_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'transport_documents_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->restrictOnDelete();
            $table->foreign(
                ['establishment_id', 'workspace_id', 'legal_entity_id'],
                'transport_documents_establishment_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('establishments')
                ->restrictOnDelete();
            $table->foreign(
                ['customer_id', 'workspace_id', 'legal_entity_id'],
                'transport_documents_customer_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->foreign(
                ['transport_document_sequence_id', 'workspace_id', 'legal_entity_id', 'establishment_id'],
                'transport_documents_sequence_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id', 'establishment_id'])
                ->on('transport_document_sequences')
                ->restrictOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'transport_documents_tenant_id_unique',
            );
            $table->unique(['workspace_id', 'document_no'], 'transport_documents_number_unique');
            $table->unique(
                ['transport_document_sequence_id', 'issue_sequence'],
                'transport_documents_sequence_number_unique',
            );
            $table->index(
                ['workspace_id', 'legal_entity_id', 'status', 'movement_date'],
                'transport_documents_register_index',
            );
            $table->index(['workspace_id', 'customer_id', 'movement_date']);
        });

        Schema::create('transport_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('transport_document_id');
            $table->unsignedBigInteger('catalogue_item_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->string('product_code', 60);
            $table->string('product_description', 200);
            $table->unsignedBigInteger('quantity_units');
            $table->unsignedTinyInteger('quantity_scale')->default(3);
            $table->string('unit_of_measure', 32);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('net_amount_minor')->default(0);
            $table->timestamps();

            $table->foreign(
                ['transport_document_id', 'workspace_id', 'legal_entity_id'],
                'transport_lines_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('transport_documents')
                ->cascadeOnDelete();
            $table->foreign(
                ['catalogue_item_id', 'workspace_id', 'legal_entity_id'],
                'transport_lines_catalogue_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('catalogue_items')
                ->restrictOnDelete();
            $table->unique(
                ['transport_document_id', 'line_number'],
                'transport_lines_number_unique',
            );
            $table->index(
                ['workspace_id', 'legal_entity_id', 'product_code'],
                'transport_lines_product_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_document_lines');
        Schema::dropIfExists('transport_documents');
        Schema::dropIfExists('transport_document_sequences');

        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->dropUnique('catalogue_items_tenant_entity_id_unique');
        });
    }
};
