<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_exports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->json('filters');
            $table->json('document_ids');
            $table->unsignedInteger('processed_documents')->default(0);
            $table->string('failure_message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_exports');
    }
};
