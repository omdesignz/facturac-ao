<?php

use App\Fiscal\TenantAiCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_model_profiles')->insert(TenantAiCatalogue::profile());
        $deployment = config('tenant_ai.deployment_id');
        if ($deployment !== null) {
            if (! is_string($deployment) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $deployment) !== 1) {
                throw new RuntimeException('Invalid AI deployment identity.');
            }
            DB::table('ai_gateway_controls')->insert(['id' => (string) Str::uuid(), 'deployment_id' => $deployment,
                'kind' => 'global', 'subject_key' => 'root', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (DB::table('tenant_ai_settings')->exists() || DB::table('activity_log')->where('event', 'like', 'assistant.ai.%')->exists()
            || DB::table('ai_gateway_controls')->where('enabled', true)->orWhereNotNull('approval_reference')->exists()) {
            throw new RuntimeException('Retained tenant AI evidence prevents rollback.');
        }
        DB::table('ai_gateway_controls')->delete();
        DB::table('ai_model_profiles')->where('id', TenantAiCatalogue::ID)->delete();
    }
};
