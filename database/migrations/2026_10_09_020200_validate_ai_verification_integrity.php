<?php

use App\Fiscal\TenantAiVerificationSchema;
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
        DB::unprepared(TenantAiVerificationSchema::validation());
    }

    public function down(): void
    {
        // Validation is not reversed; M2/M1 own guarded rollback.
    }
};
