<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing instruction to raise the same document on a schedule.
 *
 * The lines are stored as JSON rather than in their own table: a profile is a
 * template, not a document, and nothing ever needs to query across the lines of
 * every profile. Keeping them together means one row describes the whole
 * arrangement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('document_type', 4)->default('FT');
            $table->string('frequency');
            $table->boolean('is_active')->default(true);

            /**
             * Leave a draft for review, or issue outright. Issuing is
             * irreversible, so it is opt-in per profile.
             */
            $table->boolean('auto_issue')->default(false);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_on');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('generated_count')->default(0);

            $table->json('lines');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'is_active', 'next_run_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
