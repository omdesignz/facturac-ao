<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current balance for one item in one establishment.
 *
 * Derivable from the movement ledger, but kept as its own row so that issuing
 * an invoice can lock exactly one record and settle the arithmetic in place,
 * rather than re-summing a ledger that grows without limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();

            /** Signed: a negative balance is a real state worth being able to see. */
            $table->bigInteger('quantity_units')->default(0);
            $table->unsignedTinyInteger('quantity_scale')->default(3);

            /**
             * Weighted average cost per unit, in minor currency units scaled up
             * a further 1e6 so repeated averaging does not lose kwanzas to
             * rounding on cheap, high-volume items.
             */
            $table->unsignedBigInteger('average_cost_micros')->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['catalogue_item_id', 'establishment_id']);
            $table->index(['legal_entity_id', 'establishment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
