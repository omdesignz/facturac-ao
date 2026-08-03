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
        Schema::create('agt_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('environment', 32)->default('homologation');
            $table->string('schema_version', 16)->default('1.2');
            $table->text('basic_auth_username')->nullable();
            $table->text('basic_auth_password')->nullable();
            $table->string('product_id', 255)->nullable();
            $table->string('product_version', 64)->nullable();
            $table->string('software_validation_number', 255)->nullable();
            $table->string('establishment_number', 255)->nullable();
            $table->string('software_key_reference', 255)->nullable();
            $table->char('software_key_fingerprint', 64)->nullable();
            $table->string('taxpayer_key_reference', 255)->nullable();
            $table->char('taxpayer_key_fingerprint', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('configured_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();

            $table->foreign(['legal_entity_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->cascadeOnDelete();
            $table->unique(['legal_entity_id', 'environment']);
            $table->unique(['id', 'workspace_id', 'legal_entity_id']);
            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agt_connections');
    }
};
