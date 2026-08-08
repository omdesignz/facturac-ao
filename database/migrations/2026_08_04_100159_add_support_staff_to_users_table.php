<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the platform's own support people, who may impersonate a customer in
 * order to troubleshoot. Deliberately separate from workspace roles: it grants
 * nothing inside a tenant, only the ability to step into one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_support_staff')
                ->default(false)
                ->after('work_session_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_support_staff');
        });
    }
};
