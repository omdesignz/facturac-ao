<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_document_settlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_amount_minor')->nullable();
            $table->unsignedBigInteger('tax_amount_minor')->nullable();
            $table->json('withholding_allocations')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_document_settlements', function (Blueprint $table): void {
            $table->dropColumn(['net_amount_minor', 'tax_amount_minor', 'withholding_allocations']);
        });
    }
};
