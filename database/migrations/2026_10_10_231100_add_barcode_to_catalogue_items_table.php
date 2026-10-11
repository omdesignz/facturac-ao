<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The code on the pack, so a till can find an article by scanning it.
 *
 * Optional, and unique within a company only where one is set: most articles
 * will never have one, and a unique index over NULLs would still be right but
 * the partial index states the intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->after('code');
        });

        DB::statement('CREATE UNIQUE INDEX catalogue_items_barcode_unique ON catalogue_items (legal_entity_id, barcode) WHERE barcode IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalogue_items_barcode_unique');

        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->dropColumn('barcode');
        });
    }
};
