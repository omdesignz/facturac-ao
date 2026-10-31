<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer has opened the document they were sent.
 *
 * The companion to the delivery note: "did they get it" has an answer, and so
 * does "did they look". Like the delivery note it is about the sending, not
 * part of the issued document, so it lives beside it rather than in the frozen
 * evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->timestamp('first_opened_by_customer_at')->nullable()->after('send_count');
            $table->timestamp('last_opened_by_customer_at')->nullable()->after('first_opened_by_customer_at');
            $table->unsignedSmallInteger('open_count')->default(0)->after('last_opened_by_customer_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn(['first_opened_by_customer_at', 'last_opened_by_customer_at', 'open_count']);
        });
    }
};
