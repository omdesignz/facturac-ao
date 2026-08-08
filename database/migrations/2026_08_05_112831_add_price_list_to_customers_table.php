<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tabela a customer buys on.
 *
 * Nullable and set to null on delete: a customer whose list is removed falls
 * back to the catalogue price rather than losing the ability to be invoiced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('price_list_id')
                ->nullable()
                ->after('credit_limit_minor')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_list_id');
        });
    }
};
