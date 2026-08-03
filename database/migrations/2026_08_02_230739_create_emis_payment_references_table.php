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
        Schema::create('emis_payment_references', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('subscription_charge_id')->unique();
            $table->string('provider', 32)->default('pay4all');
            $table->string('environment', 24);
            $table->string('provider_reference_id', 128)->unique();
            $table->string('entity', 16);
            $table->string('reference', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3)->default('AOA');
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->char('provider_payload_sha256', 64);
            $table->text('provider_metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['subscription_charge_id', 'workspace_id'],
                'emis_references_charge_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('subscription_charges')
                ->cascadeOnDelete();
            $table->unique(['id', 'workspace_id'], 'emis_references_tenant_id_unique');
            $table->unique(
                ['provider', 'environment', 'entity', 'reference'],
                'emis_references_provider_reference_unique',
            );
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emis_payment_references');
    }
};
