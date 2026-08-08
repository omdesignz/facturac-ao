<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer was actually told that support entered their account.
 *
 * Stamped on delivery rather than on dispatch, so a queue that never ran leaves
 * the column null: "we notified them" then means it, and a gap is visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table): void {
            $table->timestamp('subject_notified_at')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table): void {
            $table->dropColumn('subject_notified_at');
        });
    }
};
