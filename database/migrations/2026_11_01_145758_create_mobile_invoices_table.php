<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document is the invoice; this row only remembers the client key that makes
 * a retried request from the phone come back as the same invoice, not a second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('client_key');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_invoices');
    }
};
