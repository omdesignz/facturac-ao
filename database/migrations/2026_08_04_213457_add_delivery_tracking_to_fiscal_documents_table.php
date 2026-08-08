<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer has actually been sent the document.
 *
 * Recorded so nobody has to guess. "Did they get the invoice?" is the question
 * behind half of all late payments, and an answer of "I think so" is what makes
 * it expensive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->timestamp('sent_to_customer_at')->nullable()->after('issued_at');
            $table->string('sent_to_email')->nullable()->after('sent_to_customer_at');
            $table->unsignedSmallInteger('send_count')->default(0)->after('sent_to_email');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn(['sent_to_customer_at', 'sent_to_email', 'send_count']);
        });
    }
};
