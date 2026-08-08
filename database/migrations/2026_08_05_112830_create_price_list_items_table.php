<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one article costs on one price list.
 *
 * The price is stored, not a discount off the catalogue. A percentage would
 * mean the figure quoted to the customer moves the moment the list price does,
 * and would put rounding back into a number both sides agreed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('unit_price_minor');
            $table->string('currency_code', 3);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['price_list_id', 'catalogue_item_id'],
                'price_list_items_list_item_unique',
            );
            $table->index(
                ['legal_entity_id', 'catalogue_item_id'],
                'price_list_items_entity_item_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
