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
        Schema::create('fiscal_document_line_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedBigInteger('fiscal_document_line_id');
            $table->string('tax_type', 8);
            $table->char('tax_country_region', 2)->default('AO');
            $table->string('tax_code', 16)->nullable();
            $table->unsignedInteger('tax_rate_basis_points')->default(0);
            $table->unsignedBigInteger('tax_contribution_minor')->default(0);
            $table->string('tax_exemption_code', 8)->nullable();
            $table->timestamps();

            $table->foreign(
                ['fiscal_document_line_id', 'workspace_id', 'legal_entity_id', 'fiscal_document_id'],
                'fiscal_document_line_taxes_line_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id', 'fiscal_document_id'])
                ->on('fiscal_document_lines')
                ->cascadeOnDelete();
            $table->unique(
                ['fiscal_document_line_id', 'tax_type', 'tax_code'],
                'fiscal_document_line_taxes_type_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_line_taxes');
    }
};
