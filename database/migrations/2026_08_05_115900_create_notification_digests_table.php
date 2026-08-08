<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the daily sweep has already said, and when.
 *
 * Kept apart from the notifications themselves because delivery is queued: a
 * sweep that read the delivered rows would repeat everything it raised while
 * the worker was still catching up. Deciding here, at the moment of raising,
 * is the only point where the answer is knowable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /** Identifies the subject, not the wording: "invoice_overdue:01K…". */
            $table->string('dedupe_key');
            $table->timestamp('last_sent_at');
            $table->unsignedInteger('times_sent')->default(1);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'dedupe_key'],
                'notification_digests_workspace_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digests');
    }
};
