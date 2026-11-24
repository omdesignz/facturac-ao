<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A code can give days of a plan for free instead of money off. The ceiling
     * lives in config('billing.coupons.max_free_days'), not in the schema.
     */
    public function up(): void
    {
        Schema::table('subscription_coupons', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_days')->nullable()->after('amount_off_minor');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_coupons', function (Blueprint $table) {
            $table->dropColumn('free_days');
        });
    }
};
