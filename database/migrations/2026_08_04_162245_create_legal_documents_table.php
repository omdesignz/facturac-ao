<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The privacy policy, terms and cookie policy, kept as versions rather than as
 * editable text.
 *
 * A published version is never rewritten: to change the terms you publish a new
 * one. That is what makes it possible to answer "what had this customer agreed
 * to on the day they issued that invoice?" — a question the AGT or a court can
 * reasonably ask, and which an in-place edit destroys the answer to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
