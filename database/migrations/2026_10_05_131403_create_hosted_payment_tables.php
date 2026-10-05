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
        Schema::create('wi_pay_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('client_fingerprint', 64);
            $table->char('token_fingerprint', 64)->unique();
            $table->string('scope', 16);
            $table->text('access_token');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['client_fingerprint', 'scope', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24)->default('wipay');
            $table->string('environment', 16);
            $table->char('client_fingerprint', 64);
            $table->uuid('provider_payment_id')->nullable();
            $table->morphs('payable');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3)->default('AOA');
            $table->text('customer_identifier');
            $table->text('signature_token')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('status', 24)->default('created');
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->unique(['payable_type', 'payable_id']);
            $table->unique(['provider', 'environment', 'client_fingerprint', 'provider_payment_id'], 'payments_provider_id_unique');
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'fulfilled_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->char('event_key', 64);
            $table->char('payload_sha256', 64);
            $table->json('safe_context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->unique(['payment_id', 'event_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wi_pay_tokens');
    }
};
