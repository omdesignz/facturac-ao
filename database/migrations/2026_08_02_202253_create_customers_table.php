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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('name');
            $table->string('tax_identification_number', 32);
            $table->char('country_code', 2)->default('AO');
            $table->string('address_line')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign(
                ['legal_entity_id', 'workspace_id'],
                'customers_legal_entity_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->cascadeOnDelete();
            $table->unique(
                ['legal_entity_id', 'tax_identification_number'],
                'customers_entity_tax_id_unique',
            );
            $table->unique(
                ['id', 'workspace_id', 'legal_entity_id'],
                'customers_tenant_entity_id_unique',
            );
            $table->index(['workspace_id', 'legal_entity_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
