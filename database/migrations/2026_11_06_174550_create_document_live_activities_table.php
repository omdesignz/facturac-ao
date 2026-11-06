<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document followed on a phone's lock screen until the AGT has answered.
 *
 * Apple gives each Live Activity its own token. It hangs off the sign-in, like
 * a push device, so signing out stops the updates, and off the document, so a
 * document leaving takes its trackers with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_live_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->string('token', 200)->unique();
            $table->string('environment', 16);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['fiscal_document_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_live_activities');
    }
};
