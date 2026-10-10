<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Purpose-scoped accounting recovery. Never decrypts, transmits, signs, promotes or refunds. */
final class TenantAiVerificationRecovery
{
    public function recover(): int
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $ids = DB::transaction(function () {
            TenantAiVerificationAdmission::limits(hrtime(true) / 1e9 + 10);

            return DB::table('assistant_provider_attempts')->useWritePdo()->where('contract_version', 'gateway_v1')->where('purpose', 'connection_probe')
                ->where('state', 'admitted')->whereRaw("admitted_at < clock_timestamp() - interval '30 seconds'")
                ->orderBy('admitted_at')->orderBy('id')->limit(100)->pluck('id');
        }, 1);
        $completed = 0;
        foreach ($ids as $id) {
            $completed += $this->abandon($id) ? 1 : 0;
        }

        return $completed;
    }

    private function abandon(string $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                TenantAiVerificationAdmission::limits(hrtime(true) / 1e9 + 10);
                $initial = DB::table('assistant_provider_attempts')->useWritePdo()->where('id', $id)->where('contract_version', 'gateway_v1')
                    ->where('purpose', 'connection_probe')->first();
                if ($initial === null) {
                    return false;
                }
                DB::table('ai_gateway_controls')->where('deployment_id', $initial->deployment_id)->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->firstOrFail();
                $budgets = [$initial->budget_id, $initial->aggregate_budget_id, $initial->usage_budget_id];
                sort($budgets, SORT_STRING);
                foreach ($budgets as $budget) {
                    DB::table('assistant_provider_controls')->where('budget_id', $budget)->lockForUpdate()->firstOrFail();
                }
                $windows = DB::table('assistant_provider_windows as w')->useWritePdo()->select('w.*')
                    ->join('assistant_provider_allocations as a', 'a.window_id', '=', 'w.id')->where('a.attempt_id', $initial->id)
                    ->orderBy('w.budget_id')->orderByRaw("CASE w.scope WHEN 'deployment_month' THEN 1 WHEN 'deployment_day' THEN 2 WHEN 'workspace_month' THEN 3 WHEN 'workspace_day' THEN 4 ELSE 5 END")
                    ->orderByRaw('w.scope_key COLLATE "C"')->orderBy('w.window_start')->limit(10)->get();
                if ($windows->count() !== 9) {
                    throw new TenantAiStorageUnavailable;
                }
                $locked = [];
                foreach ($windows as $window) {
                    $locked[] = DB::table('assistant_provider_windows')->where('id', $window->id)->lockForUpdate()->firstOrFail();
                }
                $attempt = DB::table('assistant_provider_attempts')->where('id', $initial->id)->lockForUpdate()->firstOrFail();
                $now = TenantAiVerificationAdmission::instant();
                if ($attempt->state !== 'admitted' || ! CarbonImmutable::parse($attempt->admitted_at)->lt($now->subSeconds(30))) {
                    return false;
                }
                foreach ($locked as $row) {
                    DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                        'unknown_usage_count' => TenantAiVerificationAdmission::add((int) $row->unknown_usage_count, 1)]);
                }
                DB::table('assistant_provider_attempts')->where('id', $attempt->id)->update(['state' => 'usage_unknown', 'outcome' => 'usage_unknown',
                    'verification_outcome' => 'abandoned', 'promotion_disposition' => 'abandoned', 'finalized_at' => TenantAiVerificationAdmission::time($now)]);
                $metadata = array_intersect_key((array) $attempt, array_flip(['id', 'interaction_id', 'actor_attribution_id', 'workspace_public_id',
                    'legal_entity_public_id', 'deployment_id', 'connection_id', 'credential_version_id', 'profile_id', 'purpose']));
                RequiredAudit::record(fn () => activity('assistant')->causedByAnonymous()->event('assistant.ai.verification_failed')
                    ->withProperties(['actor_kind' => 'system', ...$metadata, 'outcome' => 'abandoned', 'user_agent' => null, 'ip_address' => null])->log('Bounded verification recovery'));
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return true;
            }, 1);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }
}
