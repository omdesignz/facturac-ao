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
        Schema::create('fiscal_document_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedInteger('line_number');
            $table->string('operation_type', 4);
            $table->string('product_code', 60);
            $table->string('product_description');
            $table->unsignedBigInteger('quantity_units');
            $table->unsignedTinyInteger('quantity_scale')->default(4);
            $table->string('unit_of_measure', 32);
            $table->unsignedBigInteger('unit_price_base_minor');
            $table->unsignedBigInteger('unit_price_micros');
            $table->unsignedInteger('discount_rate_basis_points')->default(0);
            $table->unsignedBigInteger('base_amount_minor');
            $table->unsignedBigInteger('settlement_amount_minor')->default(0);
            $table->unsignedBigInteger('net_amount_minor');
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('gross_amount_minor');
            $table->timestamps();

            $table->foreign(
                ['fiscal_document_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_document_lines_document_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('fiscal_documents')
                ->cascadeOnDelete();
            $table->unique(
                ['fiscal_document_id', 'line_number'],
                'fiscal_document_lines_number_unique',
            );
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id', 'fiscal_document_id'],
                'fiscal_document_lines_tenant_id_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_lines');
    }
};
