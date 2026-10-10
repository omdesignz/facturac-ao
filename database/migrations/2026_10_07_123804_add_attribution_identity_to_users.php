<?php

use App\Fiscal\IntegrationSchemaGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'attribution_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->uuid('attribution_id')->nullable()->unique());
        }
        DB::table('users')->whereNull('attribution_id')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->whereNull('attribution_id')->update(['attribution_id' => (string) Str::uuid()]);
            }
        });
        Schema::table('users', fn (Blueprint $table) => $table->uuid('attribution_id')->nullable(false)->change());
        IntegrationSchemaGuards::uuid('users', 'attribution_id');
        IntegrationSchemaGuards::immutable('users', ['attribution_id']);
        if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('Attribution migration foreign-key failure.');
        }
    }

    public function down(): void
    {
        foreach (['integrations', 'integration_credentials'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Attribution evidence exists; forward repair required.');
            }
        }
        if (Schema::hasTable('activity_log') && DB::table('activity_log')->whereRaw("CAST(properties AS TEXT) LIKE '%attribution_id%'")->exists()) {
            throw new RuntimeException('Attribution audit evidence exists; forward repair required.');
        }
        IntegrationSchemaGuards::drop('users');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS users_attribution_id_shape');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique('users_attribution_id_unique'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('attribution_id'));
    }
};
