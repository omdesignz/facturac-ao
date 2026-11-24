<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per charge a code was applied to. The charge's own status says
     * whether the use still counts: a failed, expired or cancelled payment
     * gives the use back.
     */
    public function up(): void
    {
        Schema::create('subscription_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_charge_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('list_amount_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->timestamps();

            $table->index(['subscription_coupon_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_coupon_redemptions');
    }
};
