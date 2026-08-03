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
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 32);
            $table->string('name');
            $table->string('address_line');
            $table->string('municipality')->nullable();
            $table->string('province_code', 8);
            $table->string('timezone')->default('Africa/Luanda');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign(['legal_entity_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('legal_entities')
                ->cascadeOnDelete();
            $table->unique(['legal_entity_id', 'code']);
            $table->index(['workspace_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
