<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('configuration_revision')->default(1);
        });
        Schema::create('recurring_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('configuration_revision');
            $table->char('configuration_sha256', 64);
            $table->string('environment');
            $table->timestamp('approved_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('recurring_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->restrictOnDelete();
            $table->date('scheduled_on');
            $table->foreignId('fiscal_document_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('recurring_approval_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('configuration_revision');
            $table->timestamp('completed_at');
            $table->unique(['recurring_invoice_id', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        if (DB::table('recurring_occurrences')->exists()
            || DB::table('recurring_approvals')->exists()) {
            throw new RuntimeException('Recurring authority evidence exists; use a forward migration.');
        }
        Schema::dropIfExists('recurring_occurrences');
        Schema::dropIfExists('recurring_approvals');
        Schema::table('recurring_invoices', fn (Blueprint $table) => $table->dropColumn('configuration_revision'));
    }
};
