<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Primary admission and immutable snapshot; no network or secret access. */
final class TenantAiVerificationAdmission
{
    /** @return array{policy: TenantAiVerificationPolicy, attempt: array<string, mixed>} */
    public function admit(TenantAiCredentialVerifier $session, TenantAiVerificationContext $context, TenantAiVerificationRequest $request): array
    {
        $session->requirePhase($context, 'admitting');
        if (DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }

        return DB::transaction(function () use ($session, $context, $request): array {
            self::limits($context->deadline);
            $quota = new TenantAiVerificationQuota;
            $quota->acquire($context->authorize(), $context->deadline);
            $policy = (new TenantAiVerificationPolicyResolver)->resolve($context, $request, true);
            $discovered = self::instant();
            $windows = $this->windows($context, $policy, $discovered);
            $locked = [];
            foreach ($windows as $window) {
                $identity = array_intersect_key($window, array_flip(['budget_id', 'scope', 'scope_key', 'window_start']));
                DB::table('assistant_provider_windows')->insertOrIgnore([...$identity, 'window_end' => $window['window_end']]);
                $locked[] = DB::table('assistant_provider_windows')->where($identity)->lockForUpdate()->firstOrFail();
            }
            $instant = $quota->assertAvailable($context->authorize());
            if ($instant->format('Y-m-d') !== $discovered->format('Y-m-d')) {
                throw new TenantAiStorageUnavailable;
            }
            foreach ([['connection_id', $request->connectionId, 60, 1], ['workspace_id', $context->authorize()->workspaceId, 3600, 5]] as [$column, $value, $seconds, $limit]) {
                $recentIds = DB::table('assistant_provider_attempts')->useWritePdo()->where('contract_version', 'gateway_v1')->where('purpose', 'connection_probe')
                    ->where($column, $value)->where('admitted_at', '>', self::time($instant->subSeconds($seconds)))
                    ->orderBy('admitted_at')->orderBy('id')->limit($limit)->pluck('id');
                if ($recentIds->count() >= $limit) {
                    throw new TenantAiStorageUnavailable;
                }
            }
            if (DB::table('assistant_provider_attempts')->useWritePdo()->where('contract_version', 'gateway_v1')->where('purpose', 'connection_probe')
                ->where('connection_id', $request->connectionId)->where('state', 'admitted')->exists()) {
                throw new TenantAiStorageUnavailable;
            }
            $attempt = $this->snapshot($context, $policy, $request);
            $attempt += ['id' => (string) Str::uuid(), 'interaction_id' => (string) Str::uuid(), 'admitted_at' => self::time($instant),
                'day_start' => self::time($instant->startOfDay()), 'month_start' => self::time($instant->startOfMonth()),
                'state' => 'admitted', 'verification_outcome' => 'not_observed', 'evidence_realm' => $session->realm()];
            foreach ($locked as $index => $row) {
                $window = $windows[$index];
                $updates = [];
                foreach (['reserved_micro_usd', 'reserved_attempt_units', 'reserved_output_units'] as $unit) {
                    $amount = $window[$unit];
                    $ceiling = $this->ceiling($policy, $window, $unit);
                    if ($amount > 0 && ((int) $row->{$unit} > $ceiling - $amount || $ceiling < $amount)) {
                        throw new TenantAiStorageUnavailable;
                    }
                    $updates[$unit] = self::add((int) $row->{$unit}, $amount);
                }
                $updates['attempt_count'] = self::add((int) $row->attempt_count, 1);
                DB::table('assistant_provider_windows')->where('id', $row->id)->update($updates);
            }
            DB::table('assistant_provider_attempts')->insert($attempt);
            foreach ($locked as $index => $row) {
                DB::table('assistant_provider_allocations')->insert(['attempt_id' => $attempt['id'], 'window_id' => $row->id,
                    'role' => $windows[$index]['role'], 'created_at' => $attempt['admitted_at'],
                    ...array_intersect_key($windows[$index], array_flip(['reserved_micro_usd', 'reserved_attempt_units', 'reserved_output_units']))]);
            }
            self::audit($context, $attempt, 'verification_attempted', 'admitted');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $context->authorize();

            return ['policy' => $policy, 'attempt' => $attempt];
        }, 1);
    }

    /** @return array<string, mixed> */
    public function snapshot(TenantAiVerificationContext $context, TenantAiVerificationPolicy $policy, TenantAiVerificationRequest $request): array
    {
        $owner = $context->authorize();

        return ['workspace_id' => $owner->workspaceId, 'legal_entity_id' => $context->legalEntityId, 'deployment_id' => $owner->deploymentId,
            'environment' => 'production', 'budget_id' => $policy->connection->payer_budget_id, 'actor_attribution_id' => $context->actorAttributionId,
            'profile' => $policy->profile->profile_key, 'price_profile' => $policy->profile->price_key, 'policy' => $policy->approval->policy,
            'reserved_micro_usd' => (int) $policy->profile->reservation_micro_usd, 'estimated_tokens' => null, 'request_bytes' => 0,
            'contract_version' => 'gateway_v1', 'provider_key' => 'anthropic', 'ownership_kind' => 'customer_managed',
            'profile_id' => $policy->profile->id, 'selection_revision' => (int) $policy->settings->revision,
            'connection_id' => $request->connectionId, 'credential_version_id' => $request->credentialId,
            'endpoint_policy_key' => $policy->profile->endpoint_policy_key, 'disclosure_id' => $policy->approval->disclosure_id,
            'entitlement_revision' => (int) $policy->settings->entitlement_revision, 'purpose' => 'connection_probe', 'monetary_applicable' => true,
            'reserved_output_units' => (int) $policy->profile->output_envelope, 'usage_budget_id' => $policy->account['usage_budget_id'],
            'aggregate_budget_id' => $policy->account['aggregate_budget_id'], 'owner_approval_id' => (int) $policy->approval->id,
            'acknowledgement_id' => (int) $policy->acknowledgement->id, 'workspace_public_id' => $owner->workspacePublicId,
            'legal_entity_public_id' => $context->legalEntityPublicId, 'credential_version_number' => (int) $policy->credential->version_number,
            'credential_created_at' => self::time(CarbonImmutable::parse($policy->credential->created_at)), 'credential_wrap_revision' => (int) $policy->credential->wrap_revision,
            'expected_settings_revision' => $request->settingsRevision, 'expected_connection_revision' => $request->connectionRevision,
            'prior_selected_version_id' => $request->selectedVersionId, 'prior_active_generation' => $request->activeGeneration,
            'proposed_activated_generation' => self::add($request->activeGeneration, 1), 'actor_membership_id' => $owner->membershipId,
            'profile_manifest_sha256' => $policy->profile->manifest_sha256, 'credential_family' => $policy->profile->credential_family,
            'verifier_policy_key' => $policy->manifest['verifier_policy_key'], 'verifier_policy_sha256' => $policy->manifest['verifier_policy_sha256'],
            'account_mapping_sha256' => $policy->account['mapping_sha256'], 'authority_references' => json_encode($policy->references, JSON_THROW_ON_ERROR),
            'acknowledged_at_snapshot' => self::time(CarbonImmutable::parse($policy->acknowledgement->acknowledged_at))];
    }

    public static function limits(float $deadline): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 1
            || DB::selectOne('SHOW transaction_isolation', [], false)->transaction_isolation !== 'read committed') {
            throw new TenantAiStorageUnavailable;
        }
        $remaining = (int) floor(($deadline - hrtime(true) / 1e9) * 1000);
        if ($remaining < 1) {
            throw new TenantAiStorageUnavailable;
        }
        DB::selectOne("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)",
            [min(250, $remaining).'ms', min(1000, $remaining).'ms'], false);
    }

    public static function instant(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant', [], false)->instant)->utc();
    }

    public static function time(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function add(int $current, int $amount): int
    {
        if ($current < 0 || $amount < 0 || $current > PHP_INT_MAX - $amount) {
            throw new TenantAiStorageUnavailable;
        }

        return $current + $amount;
    }

    /** @param array<string, mixed> $attempt */
    public static function audit(TenantAiVerificationContext $context, array $attempt, string $event, string $outcome, bool $fresh = true): void
    {
        $actor = $fresh ? $context->authorize()->authorize() : User::query()->useWritePdo()->where('attribution_id', $attempt['actor_attribution_id'])->first();
        $properties = array_intersect_key($attempt, array_flip(['id', 'interaction_id', 'actor_attribution_id', 'workspace_public_id',
            'legal_entity_public_id', 'environment', 'deployment_id', 'connection_id', 'credential_version_id', 'profile_id', 'provider_key',
            'ownership_kind', 'purpose', 'expected_settings_revision', 'expected_connection_revision', 'prior_active_generation',
            'proposed_activated_generation', 'evidence_id']));
        RequiredAudit::record(fn () => activity('assistant')->causedBy($actor)->event('assistant.ai.'.$event)
            ->withProperties(['actor_kind' => 'human', ...$properties, 'outcome' => $outcome, 'user_agent' => null, 'ip_address' => null])
            ->log('Bounded credential verification'));
    }

    /** @return list<array<string, mixed>> */
    private function windows(TenantAiVerificationContext $context, TenantAiVerificationPolicy $policy, CarbonImmutable $instant): array
    {
        $owner = $context->authorize();
        $roles = [$policy->account['usage_budget_id'] => 'shared_usage', $policy->account['aggregate_budget_id'] => 'customer_aggregate',
            $policy->connection->payer_budget_id => 'customer_account'];
        ksort($roles, SORT_STRING);
        $windows = [];
        foreach ($roles as $budget => $role) {
            $scopes = $role === 'shared_usage' ? ['deployment_month', 'deployment_day', 'workspace_month', 'workspace_day', 'user_day'] : ['workspace_month', 'workspace_day'];
            foreach ($scopes as $scope) {
                $start = str_ends_with($scope, 'month') ? $instant->startOfMonth() : $instant->startOfDay();
                $windows[] = ['budget_id' => $budget, 'role' => $role, 'scope' => $scope,
                    'scope_key' => str_starts_with($scope, 'deployment') ? 'deployment' : ($scope === 'user_day' ? $context->actorAttributionId : (string) $owner->workspaceId),
                    'window_start' => self::time($start), 'window_end' => self::time(str_ends_with($scope, 'month') ? $start->addMonth() : $start->addDay()),
                    'reserved_micro_usd' => $role === 'shared_usage' ? 0 : (int) $policy->profile->reservation_micro_usd,
                    'reserved_attempt_units' => $role === 'shared_usage' ? 1 : 0,
                    'reserved_output_units' => $role === 'shared_usage' ? (int) $policy->profile->output_envelope : 0];
            }
        }

        return $windows;
    }

    /** @param array<string, mixed> $window */
    private function ceiling(TenantAiVerificationPolicy $policy, array $window, string $unit): int
    {
        if ($window[$unit] === 0) {
            return 0;
        }
        $cap = $policy->account['limits'][$window['budget_id']][$window['scope']][$unit] ?? null;
        if (! is_int($cap) || $cap <= 0) {
            throw new TenantAiStorageUnavailable;
        }
        if (str_starts_with($window['scope'], 'workspace')) {
            $period = str_ends_with($window['scope'], 'month') ? 'month' : 'day';
            $field = match ($unit) {
                'reserved_attempt_units' => 'attempts_'.$period.'_cap', 'reserved_output_units' => 'output_'.$period.'_cap',
                default => 'money_'.$period.'_micro_usd_cap',
            };
            $cap = min($cap, (int) $policy->settings->{$field});
        }

        return $cap;
    }
}
