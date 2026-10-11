<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shift on a register: the float counted in, the sales rung up, the drawer
 * counted out.
 *
 * Two partial unique indexes carry the rules the application also checks, so
 * a race between two tabs cannot open a second shift on the same till or give
 * one cashier two tills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->char('currency_code', 3);
            $table->bigInteger('opening_float_minor');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('cash_difference_minor')->nullable();
            $table->string('closing_notes', 500)->nullable();
            /** The summary as it stood when the shift closed, so the report never moves. */
            $table->json('closing_summary')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'opened_at']);
        });

        DB::statement("CREATE UNIQUE INDEX pos_sessions_one_open_per_register ON pos_sessions (pos_register_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX pos_sessions_one_open_per_user ON pos_sessions (legal_entity_id, opened_by_user_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pos_sessions_one_open_per_register');
        DB::statement('DROP INDEX IF EXISTS pos_sessions_one_open_per_user');

        Schema::dropIfExists('pos_sessions');
    }
};
