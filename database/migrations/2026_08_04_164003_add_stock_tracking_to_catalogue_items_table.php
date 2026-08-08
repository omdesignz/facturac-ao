<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns an item into something with a quantity.
 *
 * Off by default, and meaningless for services: a consulting hour has no
 * warehouse balance to draw down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->boolean('tracks_stock')->default(false)->after('is_active');

            /**
             * Quantities are scaled integers, like the fiscal document lines:
             * 3 decimal places covers kilograms and litres without ever
             * introducing a float into a number that has to balance.
             */
            $table->unsignedTinyInteger('stock_scale')->default(3)->after('tracks_stock');

            /** Warn at or below this. Null means never warn. */
            $table->unsignedBigInteger('reorder_level_units')->nullable()->after('stock_scale');
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_items', function (Blueprint $table): void {
            $table->dropColumn(['tracks_stock', 'stock_scale', 'reorder_level_units']);
        });
    }
};
