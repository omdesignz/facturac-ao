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
        Schema::create('billing_payment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('emis_payment_reference_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('provider_event_id', 128)->nullable()->unique();
            $table->char('payload_sha256', 64);
            $table->json('safe_context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(
                ['emis_payment_reference_id', 'workspace_id'],
                'billing_events_reference_tenant_fk',
            )->references(['id', 'workspace_id'])
                ->on('emis_payment_references')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['emis_payment_reference_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_payment_events');
    }
};
