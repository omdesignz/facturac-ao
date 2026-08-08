<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts carry how and when they were paid. FR settles itself at issue; RC
 * settles earlier invoices through the fiscal_document_settlements table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->string('payment_method', 2)->nullable()->after('adjustment_reason');
            $table->unsignedBigInteger('payment_amount_minor')->nullable()->after('payment_method');
            $table->date('payment_date')->nullable()->after('payment_amount_minor');
        });

        Schema::create('fiscal_document_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            // The receipt.
            $table->foreignId('fiscal_document_id')->constrained('fiscal_documents')->cascadeOnDelete();
            // The invoice it pays off.
            $table->foreignId('settled_document_id')->constrained('fiscal_documents')->restrictOnDelete();
            $table->string('settled_document_no');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['fiscal_document_id', 'settled_document_id'], 'settlements_receipt_invoice_unique');
            $table->index('settled_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_settlements');

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'payment_amount_minor', 'payment_date']);
        });
    }
};
