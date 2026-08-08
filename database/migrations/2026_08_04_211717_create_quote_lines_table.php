<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a quote is offering, line by line.
 *
 * Money is kept in minor units and tax as a stored percentage string, matching
 * the fiscal document lines this converts into, so the conversion is a copy
 * rather than a recalculation that could land on a different total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->string('product_code')->nullable();
            $table->string('product_description');
            $table->string('unit_of_measure', 20)->default('UN');

            /** Scaled integer, like the fiscal lines: three decimal places. */
            $table->unsignedBigInteger('quantity_units');
            $table->unsignedTinyInteger('quantity_scale')->default(3);

            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedInteger('discount_rate_basis_points')->default(0);

            $table->string('tax_type', 10)->default('IVA');
            $table->string('tax_code', 10)->nullable();
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->string('tax_exemption_code')->nullable();

            $table->unsignedBigInteger('net_amount_minor')->default(0);
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('gross_amount_minor')->default(0);

            $table->timestamps();

            $table->unique(['quote_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
