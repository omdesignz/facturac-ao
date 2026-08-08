<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas de preços — a named set of prices several customers can share.
 *
 * The per-customer override in `customer_prices` came first and stays: it is
 * the right shape for a price agreed with one buyer. It is the wrong shape for
 * "everyone who buys at wholesale", which is what shops actually maintain — and
 * which, kept as overrides, means editing every customer to change one price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 255)->nullable();
            $table->string('currency_code', 3);

            /**
             * The list a customer joins when none is chosen for them. Only one
             * per legal entity may hold it; the application clears the previous
             * holder when setting it, since a partial unique index is not
             * portable across SQLite and MySQL.
             */
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['legal_entity_id', 'name'], 'price_lists_entity_name_unique');
            $table->index(['legal_entity_id', 'is_active'], 'price_lists_entity_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
