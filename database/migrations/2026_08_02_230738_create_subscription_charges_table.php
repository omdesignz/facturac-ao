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
        Schema::create('subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_subscription_id')->nullable();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('checkout_token')->unique();
            $table->string('active_checkout_key')->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3)->default('AOA');
            $table->unsignedSmallInteger('provider_fee_basis_points')->default(100);
            $table->unsignedBigInteger('estimated_provider_fee_minor')->default(0);
            $table->string('status', 24)->default('creating');
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            $table->foreign(
                ['workspace_subscription_id', 'workspace_id'],
                'subscription_charges_subscription_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('workspace_subscriptions')
                ->restrictOnDelete();
            $table->unique(['id', 'workspace_id'], 'subscription_charges_tenant_id_unique');
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['status', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_charges');
    }
};
