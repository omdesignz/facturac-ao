<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_devices', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->text('token')->change();
            $table->string('provider', 8)->default('apns');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('bundle_id', 100)->nullable();
            $table->string('binding', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('push_devices')->where('provider', '!=', 'apns')->exists()) {
            throw new RuntimeException('Remove Android device registrations before rolling back Android delivery.');
        }

        Schema::table('push_devices', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['provider', 'token_hash', 'bundle_id', 'binding']);
            $table->string('token', 200)->change();
            $table->unique('token');
        });
    }
};
