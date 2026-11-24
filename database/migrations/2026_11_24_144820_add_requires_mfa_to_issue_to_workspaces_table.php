<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether issuing a fiscal document needs two-step verification. On for
     * every company, as before; the owner may waive it.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('requires_mfa_to_issue')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('requires_mfa_to_issue');
        });
    }
};
