<?php

namespace App\Fiscal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Frozen-schema coexistence only: legacy inference retains its own wire and monetary semantics. */
final class TenantAiLegacyAccounting
{
    public static function authorize(string $budget): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $mapping = config('tenant_ai.legacy_accounting.'.$budget);
        abort_unless(is_array($mapping) && ($mapping['reconciled'] ?? false) === true
            && is_string($mapping['deployment_id'] ?? null) && is_string($mapping['usage_budget_id'] ?? null), 503);
        $root = DB::table('ai_gateway_controls')->useWritePdo()->where('deployment_id', $mapping['deployment_id'])
            ->where('kind', 'global')->where('subject_key', 'root')->first();
        $shared = DB::table('assistant_provider_controls')->useWritePdo()->where('budget_id', $mapping['usage_budget_id'])
            ->where('contract_version', 'gateway_v1')->where('deployment_id', $mapping['deployment_id'])
            ->where('ownership_kind', 'shared_usage')->where('account_role', 'usage')->first();
        abort_unless($root !== null && ! $root->circuit_blocked && $shared !== null && $shared->enabled && ! $shared->circuit_blocked
            && $shared->approval_reference !== null && $shared->approval_expires_at !== null
            && TenantAiVerificationAdmission::instant()->lt(CarbonImmutable::parse($shared->approval_expires_at)), 503);
    }

    /** @return array<string, mixed>|null */
    public static function lock(string $budget): ?array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }
        abort_unless(DB::transactionLevel() === 1 && DB::selectOne('SHOW transaction_isolation', [], false)->transaction_isolation === 'read committed', 503);
        $mapping = config('tenant_ai.legacy_accounting.'.$budget);
        abort_unless(is_array($mapping) && ($mapping['reconciled'] ?? false) === true
            && is_string($mapping['deployment_id'] ?? null) && is_string($mapping['usage_budget_id'] ?? null), 503);
        $root = DB::table('ai_gateway_controls')->where('deployment_id', $mapping['deployment_id'])->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->first();
        abort_unless($root !== null, 503);
        $ids = [$budget, $mapping['usage_budget_id']];
        sort($ids, SORT_STRING);
        $rows = DB::table('assistant_provider_controls')->whereIn('budget_id', $ids)->orderBy('budget_id')->lockForUpdate()->get();
        $legacy = $rows->firstWhere('budget_id', $budget);
        $shared = $rows->firstWhere('budget_id', $mapping['usage_budget_id']);
        abort_unless($rows->count() === 2 && $legacy?->contract_version === 'legacy'
            && $shared?->contract_version === 'gateway_v1' && $shared->deployment_id === $mapping['deployment_id']
            && $shared->ownership_kind === 'shared_usage' && $shared->account_role === 'usage', 503);

        return $mapping;
    }

    /** @param list<array<string, mixed>> $legacy
     * @param  array<string, mixed>|null  $mapping
     * @return list<array<string, mixed>>
     */
    public static function windows(array $legacy, ?array $mapping): array
    {
        if ($mapping === null) {
            return $legacy;
        }
        $shared = array_map(fn (array $window): array => [...$window, 'budget_id' => $mapping['usage_budget_id']], $legacy);

        return strcmp($legacy[0]['budget_id'], $mapping['usage_budget_id']) < 0 ? [...$legacy, ...$shared] : [...$shared, ...$legacy];
    }

    /** @param array<string, mixed> $mapping
     * @param  array<string, mixed>  $window
     */
    public static function reserve(\stdClass $row, array $window, array $mapping): void
    {
        $root = DB::table('ai_gateway_controls')->useWritePdo()->where('deployment_id', $mapping['deployment_id'])
            ->where('kind', 'global')->where('subject_key', 'root')->first();
        abort_unless($root !== null && ! $root->circuit_blocked, 503);
        $control = DB::table('assistant_provider_controls')->useWritePdo()->where('budget_id', $mapping['usage_budget_id'])->first();
        abort_unless($control !== null && $control->enabled && ! $control->circuit_blocked
            && $control->approval_expires_at !== null && CarbonImmutable::parse($control->approval_expires_at)->isFuture(), 503);
        $updates = [];
        foreach (['reserved_attempt_units' => 1, 'reserved_output_units' => AssistantProviderProfile::OUTPUT_LIMIT] as $field => $units) {
            $cap = $mapping['limits'][$window['scope']][$field] ?? null;
            abort_unless(is_int($cap) && $cap > 0, 503);
            abort_if($cap < $units || (int) $row->{$field} > $cap - $units, 429);
            $updates[$field] = TenantAiVerificationAdmission::add((int) $row->{$field}, $units);
        }
        $updates['attempt_count'] = TenantAiVerificationAdmission::add((int) $row->attempt_count, 1);
        DB::table('assistant_provider_windows')->where('id', $row->id)->update($updates);
    }
}
