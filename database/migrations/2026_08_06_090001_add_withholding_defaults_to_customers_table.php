<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this buyer normally withholds.
 *
 * Whether tax is kept back is a fact about the customer, not about the sale —
 * a State body or a large taxpayer withholds on everything they buy. Recording
 * it once means the person issuing the invoice is reminded rather than expected
 * to remember, which is where this goes wrong in practice.
 *
 * A default, not a rule: every document can still say otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('withholding_type', 16)->nullable()->after('credit_limit_minor');
            $table->unsignedInteger('withholding_rate_basis_points')->nullable()->after('withholding_type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['withholding_type', 'withholding_rate_basis_points']);
        });
    }
};
