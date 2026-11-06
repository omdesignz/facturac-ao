<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer or item is the record; this row only remembers the client key that
 * makes a retried request from the phone come back as the same record, not a refusal
 * that it already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_created_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('catalogue_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('client_key');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_created_records');
    }
};
