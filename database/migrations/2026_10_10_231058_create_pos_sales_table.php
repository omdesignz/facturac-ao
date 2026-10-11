<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a shift to the Factura/Recibo it produced.
 *
 * The document is the sale; this row only remembers which shift rang it up,
 * how much cash changed hands, and the client key that makes a retried request
 * come back as the same sale instead of a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->unique()->constrained()->restrictOnDelete();
            $table->string('client_key', 64);
            $table->string('payment_method', 2);
            $table->bigInteger('total_minor');
            $table->bigInteger('tendered_minor');
            $table->bigInteger('change_minor');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'client_key']);
            $table->index(['pos_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
