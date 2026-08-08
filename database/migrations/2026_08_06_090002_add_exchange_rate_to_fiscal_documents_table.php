<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate a foreign-currency document was issued at.
 *
 * Kwanzas per one unit of the document's currency, as an integer scaled by a
 * million. A float would be the obvious choice and the wrong one: this figure
 * multiplies every total on the document, and the result has to agree with the
 * audit file to the cêntimo years from now.
 *
 * Six decimal places because the kwanza has been weak enough for a rate to need
 * them, and because the AGT compares what was declared against what was filed.
 *
 * Every existing document is in kwanzas, so the default of exactly one is not a
 * placeholder — it is the true rate for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('exchange_rate_micro')
                ->default(1_000_000)
                ->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn('exchange_rate_micro');
        });
    }
};
