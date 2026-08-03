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
        Schema::create('catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 60);
            $table->string('type', 24);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('unit_of_measure', 32)->default('UN');
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->char('currency_code', 3)->default('AOA');
            $table->string('tax_type', 8)->default('IVA');
            $table->string('tax_code', 8)->nullable();
            $table->decimal('tax_percentage', 5, 2)->default(14);
            $table->string('tax_exemption_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'catalogue_items_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->cascadeOnDelete();
            $table->unique(
                ['legal_entity_id', 'code'],
                'catalogue_items_entity_code_unique',
            );
            $table->index(['workspace_id', 'legal_entity_id', 'is_active']);
        });

        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type', 32);
            $table->string('source', 32);
            $table->string('status', 32);
            $table->string('original_name');
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 1024)->nullable();
            $table->string('file_extension', 12);
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->json('headers')->nullable();
            $table->json('column_mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('mapped_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'data_imports_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->cascadeOnDelete();
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'data_imports_tenant_entity_id_unique',
            );
            $table->index(['workspace_id', 'legal_entity_id', 'created_at']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'sha256']);
        });

        Schema::create('data_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_import_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedInteger('row_number');
            $table->string('status', 24)->default('pending');
            $table->mediumText('source_payload');
            $table->mediumText('normalized_payload')->nullable();
            $table->mediumText('validation_errors')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();

            $table->foreign(
                ['data_import_id', 'workspace_id', 'legal_entity_id'],
                'data_import_rows_tenant_import_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('data_imports')
                ->cascadeOnDelete();
            $table->unique(['data_import_id', 'row_number']);
            $table->index(['data_import_id', 'status', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_import_rows');
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('catalogue_items');
    }
};
