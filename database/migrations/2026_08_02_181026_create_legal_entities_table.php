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
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('tax_identification_number', 32)->nullable();
            $table->string('tax_regime', 32)->nullable();
            $table->string('main_cae_code', 16)->nullable();
            $table->string('status', 32)->default('draft');
            $table->char('country_code', 2)->default('AO');
            $table->char('currency_code', 3)->default('AOA');
            $table->string('timezone')->default('Africa/Luanda');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'tax_identification_number']);
            $table->unique(['id', 'workspace_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_entities');
    }
};
