<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who agreed to which version, when, and from where.
 *
 * Cookie choices are recorded here too, including a refusal: being able to show
 * that someone declined is as important as showing that they consented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->unsignedInteger('version');

            /** For the cookie policy: which optional categories were allowed. */
            $table->json('choices')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
