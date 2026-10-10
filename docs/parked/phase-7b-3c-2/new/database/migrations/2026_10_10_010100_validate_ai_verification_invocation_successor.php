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

    public function up(): void
    {
        DB::unprepared(TenantAiInvocationSchema::validation());
    }

    public function down(): void
    {
        // Validation is not reversed; the installation migration owns the guarded rollback.
    }
};
