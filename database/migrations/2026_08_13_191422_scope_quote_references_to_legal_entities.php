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
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique('quotes_reference_unique');
            $table->unique(
                ['legal_entity_id', 'reference'],
                'quotes_legal_entity_reference_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique('quotes_legal_entity_reference_unique');
            $table->unique('reference');
        });
    }
};
