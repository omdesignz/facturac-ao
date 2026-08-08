<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complaints book.
 *
 * Angolan consumer law gives customers a right to complain and to escalate to
 * INADEC if the answer does not satisfy them. That makes this a register rather
 * than a support inbox: entries get a citable reference, are never deleted, and
 * record when the customer was answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            /** Short, human-quotable, and what the customer is told to cite. */
            $table->string('reference')->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->string('status')->index();
            $table->string('subject');
            $table->text('body');

            /** Captured at submission so a closed account can still be answered. */
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 40)->nullable();

            $table->text('resolution')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            /** The working-day deadline we committed to when it was filed. */
            $table->timestamp('response_due_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
