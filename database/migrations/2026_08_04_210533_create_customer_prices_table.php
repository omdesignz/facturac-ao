<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agreed price for one item with one customer.
 *
 * Overrides the catalogue price when a document is built for that customer.
 * Stored rather than derived from a discount percentage because what gets
 * agreed in practice is a number, and a percentage would reintroduce rounding
 * into a figure both sides already shook hands on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('unit_price_minor');
            $table->string('currency_code', 3);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'catalogue_item_id'], 'customer_prices_customer_item_unique');
            $table->index(['legal_entity_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
    }
};
