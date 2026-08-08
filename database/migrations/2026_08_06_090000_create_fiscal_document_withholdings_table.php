<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax the buyer keeps back and pays to the AGT themselves.
 *
 * A row per withholding rather than a column on the document, because a single
 * supply can carry more than one — a services invoice to a State body can be
 * both subject to income-tax retention and have its VAT captived, at different
 * rates on different bases.
 *
 * The base is stored alongside the rate instead of being recomputed from the
 * document. What was withheld was withheld on the figures of the day, and a
 * credit note issued later must not silently restate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->string('withholding_type', 16);
            $table->unsignedBigInteger('base_minor');
            $table->unsignedInteger('rate_basis_points');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            // One entry per tax on a document: two different rates of the same
            // retention on the same supply is a data-entry mistake, not a case.
            $table->unique(
                ['fiscal_document_id', 'withholding_type'],
                'fiscal_document_withholdings_document_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_withholdings');
    }
};
