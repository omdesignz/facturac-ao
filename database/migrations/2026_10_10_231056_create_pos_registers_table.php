<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till at an establishment.
 *
 * The register is only a place to stand: it names where a shift happens and
 * which establishment's series and stock the sales draw on. Nothing fiscal
 * lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['legal_entity_id', 'establishment_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_registers');
    }
};
