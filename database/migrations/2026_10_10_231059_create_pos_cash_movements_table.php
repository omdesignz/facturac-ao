<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money put in or taken out of the drawer during a shift, other than by a sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 8);
            $table->bigInteger('amount_minor');
            $table->string('reason', 160);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['pos_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};
