<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->date('collection_next_contact_on')->nullable();
            $table->date('collection_promised_payment_on')->nullable();
            $table->text('collection_note')->nullable();
            $table->timestamp('collection_completed_at')->nullable();
            $table->unsignedInteger('collection_revision')->default(0);
            $table->foreignId('collection_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collection_updated_at')->nullable();
            $table->index(['workspace_id', 'legal_entity_id', 'collection_completed_at'], 'customers_collection_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_collection_queue_index');
            $table->dropConstrainedForeignId('collection_updated_by');
            $table->dropColumn(['collection_next_contact_on', 'collection_promised_payment_on', 'collection_note', 'collection_completed_at', 'collection_revision', 'collection_updated_at']);
        });
    }
};
