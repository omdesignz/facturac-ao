<?php

use App\Fiscal\TenantAiInvocationSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** SQLite retains the closed historical boundary; PostgreSQL owns this deferred-integrity gate. */
    public function shouldRun(): bool
    {
        return DB::getDriverName() !== 'sqlite';
    }

    /** One statement batch, so the installation is atomic even when called outside the migrator. */
    public function up(): void
    {
        DB::unprepared(TenantAiInvocationSchema::install());
    }

    public function down(): void
    {
        DB::unprepared(TenantAiInvocationSchema::down());
    }
};
