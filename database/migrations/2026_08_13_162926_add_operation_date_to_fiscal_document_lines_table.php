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
        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->date('operation_date')->nullable()->after('operation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->dropColumn('operation_date');
        });
    }
};
