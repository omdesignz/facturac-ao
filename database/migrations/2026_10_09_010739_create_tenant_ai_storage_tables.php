<?php

use App\Fiscal\TenantAiSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TenantAiSchema::create();
    }

    public function down(): void
    {
        TenantAiSchema::drop();
    }
};
