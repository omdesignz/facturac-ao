<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every change to a stock balance.
 *
 * Rows are never updated or deleted: a mistaken entry is corrected by recording
 * the opposite movement, exactly as a mistaken invoice is corrected by a credit
 * note. That keeps the balance reconstructible from the history, which is the
 * only way to answer "how did we get to this number?" months later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();

            $table->string('type');

            /** Signed: negative removes stock, positive adds it. */
            $table->bigInteger('quantity_units');
            $table->unsignedTinyInteger('quantity_scale');

            /** The balance immediately after this movement, for auditing. */
            $table->bigInteger('balance_after_units');

            /** Unit cost for inbound movements; the running average for outbound. */
            $table->unsignedBigInteger('unit_cost_micros')->default(0);

            /** What caused it: the document issued, or the person who typed it. */
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(
                ['catalogue_item_id', 'establishment_id', 'moved_at'],
                'stock_movements_item_place_moved_index',
            );
            $table->index(['legal_entity_id', 'moved_at']);
            $table->index('fiscal_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
