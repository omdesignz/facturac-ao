<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AGT operation type each quoted line will carry once invoiced.
 *
 * Stored on the quote so conversion copies it rather than guessing. Defaults to
 * a general service, which is what most quotes are for; goods take 'TB'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->string('operation_type', 8)->default('SG')->after('product_code');
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropColumn('operation_type');
        });
    }
};
