<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quote made on the phone carries a key, so one repeated after a dropped
 * connection comes back as the same quote instead of taking a second reference.
 * The web's form submits once and sends none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->uuid('client_key')->nullable()->after('notes');
            $table->unique(['legal_entity_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['legal_entity_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
