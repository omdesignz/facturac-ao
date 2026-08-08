<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per troubleshooting session: who looked at whose account, why, from
 * where, for how long, and what they changed while they were in there.
 *
 * Rows are never deleted when a user is: the audit trail has to outlive the
 * account it describes, which is why the foreign keys restrict rather than
 * cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('impersonator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by')->nullable();
            $table->unsignedInteger('write_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->timestamps();

            $table->index(['subject_id', 'started_at']);
            $table->index(['impersonator_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
