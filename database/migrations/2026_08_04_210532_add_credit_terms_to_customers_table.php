<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was agreed with this customer about paying later.
 *
 * Terms belong on the customer rather than being typed per invoice: the whole
 * point of a conta corrente is that the agreement is standing, and a due date
 * derived from it is one fewer thing to get wrong at the moment of issuing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            /** Days from issue until payment falls due. Zero means on delivery. */
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('is_active');

            /** How much may be owed at once. Null means no ceiling was agreed. */
            $table->unsignedBigInteger('credit_limit_minor')->nullable()->after('payment_terms_days');

            /** Send issued documents to this customer without being asked. */
            $table->boolean('auto_send_documents')->default(false)->after('credit_limit_minor');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_terms_days',
                'credit_limit_minor',
                'auto_send_documents',
            ]);
        });
    }
};
