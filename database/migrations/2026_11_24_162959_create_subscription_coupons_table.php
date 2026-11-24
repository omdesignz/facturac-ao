<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discount codes support staff hand out for facturac.ao's own plans.
     * Amounts are minor units; a code is stored upper-case so it is unique
     * however a customer types it.
     */
    public function up(): void
    {
        Schema::create('subscription_coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 32)->unique();
            $table->string('description', 160)->nullable();
            $table->string('discount_type', 16);
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off_minor')->nullable();
            $table->char('currency_code', 3)->default('AOA');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_coupons');
    }
};
