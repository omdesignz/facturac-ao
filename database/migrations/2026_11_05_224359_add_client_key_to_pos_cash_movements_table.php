<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A movement sent from the phone carries a key, so one repeated after a dropped
 * connection comes back as the same movement instead of moving the money twice.
 * The web's form submits once and sends none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table): void {
            $table->uuid('client_key')->nullable()->after('reason');
            $table->unique(['legal_entity_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table): void {
            $table->dropUnique(['legal_entity_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
