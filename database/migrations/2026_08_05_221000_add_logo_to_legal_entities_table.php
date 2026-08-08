<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's mark, for the head of its documents.
 *
 * A path on a private disk rather than a public URL: a logo is not secret, but
 * the documents it appears on are, and serving both through the same signed
 * route keeps one rule instead of two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
